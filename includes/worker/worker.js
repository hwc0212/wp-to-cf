/**
 * wp-to-cf Worker
 *
 * 职责：
 * 1. 托管静态资源（env.ASSETS）
 * 2. 接收公网静态站的表单/评论提交（/__wptocf/submit、/__wptocf/comment）
 *    - 写入 D1（status=pending）
 *    - 按配置的邮件后端（SES / HTTP API / SMTP）发送通知邮件
 *    - 将发出的邮件内容与发送状态一并写入 D1（便于失败后补救）
 * 3. 供内网 WordPress 出站拉取与回执（/__wptocf/pull、/__wptocf/ack，共享密钥鉴权）
 *
 * 所有配置通过环境变量/绑定注入（部署时由插件同步）：
 *   绑定：
 *     ASSETS               静态资源绑定
 *     DB                   D1 数据库绑定（可选，未绑定时提交接口返回 503）
 *   变量/密钥：
 *     WPTOCF_PULL_SECRET   pull/ack 鉴权共享密钥（secret）
 *     WPTOCF_TURNSTILE_SECRET  Turnstile 密钥（secret，可选；配置后校验人机）
 *     WPTOCF_MAIL_PROVIDER 邮件后端：ses | http | smtp | none
 *     WPTOCF_MAIL_FROM     发件人（如 "Name <no-reply@example.com>" 或纯地址）
 *     WPTOCF_MAIL_TO       通知收件人
 *     WPTOCF_MAIL_SUBJECT  邮件主题模板（可含 {type} {form_id}）
 *     -- SES --
 *     WPTOCF_SES_REGION            区域，如 us-east-1
 *     WPTOCF_SES_ACCESS_KEY_ID     Access Key ID
 *     WPTOCF_SES_SECRET_ACCESS_KEY Secret Access Key（secret）
 *     -- HTTP API（Resend 风格）--
 *     WPTOCF_HTTP_ENDPOINT  发送端点，如 https://api.resend.com/emails
 *     WPTOCF_HTTP_API_KEY   Bearer 令牌（secret）
 *     -- SMTP --
 *     WPTOCF_SMTP_HOST      主机
 *     WPTOCF_SMTP_PORT      端口（587=STARTTLS，465=TLS）
 *     WPTOCF_SMTP_USER      账号
 *     WPTOCF_SMTP_PASS      密码（secret）
 */

import { connect } from "cloudflare:sockets";

const ROUTE_PREFIX = "/__wptocf/";
const MAX_BODY_BYTES = 256 * 1024; // 256KB 上限，防滥用

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    if (url.pathname.startsWith(ROUTE_PREFIX)) {
      try {
        return await handleApi(request, env, url, ctx);
      } catch (err) {
        return json({ ok: false, error: String(err && err.message || err) }, 500);
      }
    }

    return env.ASSETS.fetch(request);
  },
};

/* ------------------------------------------------------------------ *
 * 路由
 * ------------------------------------------------------------------ */

async function handleApi(request, env, url, ctx) {
  const route = url.pathname.slice(ROUTE_PREFIX.length).replace(/\/+$/, "");
  const method = request.method.toUpperCase();

  if (route === "submit" && method === "POST") {
    return handleIntake(request, env, ctx, "form");
  }
  if (route === "comment" && method === "POST") {
    return handleIntake(request, env, ctx, "comment");
  }
  if (route === "pull" && method === "GET") {
    return handlePull(request, env, url);
  }
  if (route === "ack" && method === "POST") {
    return handleAck(request, env);
  }
  if (route === "health" && method === "GET") {
    return json({ ok: true, hasDb: !!env.DB });
  }

  return json({ ok: false, error: "not found" }, 404);
}

/* ------------------------------------------------------------------ *
 * 提交入口（表单 / 评论）
 * ------------------------------------------------------------------ */

async function handleIntake(request, env, ctx, type) {
  if (!env.DB) {
    return json({ ok: false, error: "storage not configured" }, 503);
  }

  const fields = await readBody(request);
  if (fields === null) {
    return json({ ok: false, error: "invalid or too large body" }, 400);
  }

  // Turnstile 人机校验（配置了密钥才校验）
  if (env.WPTOCF_TURNSTILE_SECRET) {
    const token =
      fields["cf-turnstile-response"] ||
      fields["cf_turnstile_response"] ||
      fields["turnstile"] ||
      "";
    const ok = await verifyTurnstile(env.WPTOCF_TURNSTILE_SECRET, token, request);
    if (!ok) {
      return json({ ok: false, error: "human verification failed" }, 403);
    }
  }

  const now = new Date().toISOString();
  const id = crypto.randomUUID();
  const formId = String(fields.form_id || fields._form || fields.form || "default");
  const postId = parseInt(fields.post_id || fields._post_id || "0", 10) || 0;

  // 移除内部字段后作为提交数据存档
  const data = { ...fields };
  delete data["cf-turnstile-response"];
  delete data["cf_turnstile_response"];
  delete data["turnstile"];

  await env.DB.prepare(
    `INSERT INTO submissions (id, type, form_id, post_id, data, status, created_at)
     VALUES (?, ?, ?, ?, ?, 'pending', ?)`
  )
    .bind(id, type, formId, postId, JSON.stringify(data), now)
    .run();

  // 仅表单提交发送通知邮件（评论走 WordPress 审核队列，不发通知）
  const provider = (env.WPTOCF_MAIL_PROVIDER || "none").toLowerCase();
  if (type === "form" && provider !== "none") {
    const mail = buildNotificationEmail(env, type, formId, data);
    const emailId = crypto.randomUUID();

    // 无论成败都记录到 D1，便于发送失败后人工补救
    await env.DB.prepare(
      `INSERT INTO emails (id, submission_id, to_addr, from_addr, subject, body, provider, status, attempts, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?)`
    )
      .bind(emailId, id, mail.to || "", mail.from || "", mail.subject || "", mail.text || "", provider, now)
      .run();

    const sendTask = sendAndRecord(env, emailId, mail);
    if (ctx && typeof ctx.waitUntil === "function") {
      ctx.waitUntil(sendTask);
    } else {
      await sendTask;
    }
  }

  return json({ ok: true, id });
}

/**
 * 执行发送并把结果写回 emails 表
 */
async function sendAndRecord(env, emailId, mail) {
  const provider = (env.WPTOCF_MAIL_PROVIDER || "none").toLowerCase();
  let status = "failed";
  let errorMsg = "";

  try {
    if (!mail.to || !mail.from) {
      throw new Error("mail from/to not configured");
    }
    if (provider === "ses") {
      await sendViaSES(env, mail);
    } else if (provider === "http") {
      await sendViaHTTP(env, mail);
    } else if (provider === "smtp") {
      await sendViaSMTP(env, mail);
    } else {
      throw new Error("no mail provider configured");
    }
    status = "sent";
  } catch (err) {
    status = "failed";
    errorMsg = String((err && err.message) || err).slice(0, 500);
  }

  try {
    await env.DB.prepare(
      `UPDATE emails SET status = ?, error = ?, attempts = attempts + 1, sent_at = ? WHERE id = ?`
    )
      .bind(status, errorMsg, new Date().toISOString(), emailId)
      .run();
  } catch (_) {
    // 记录失败不影响主流程
  }
}

/* ------------------------------------------------------------------ *
 * 拉取 / 回执（供内网 WordPress 出站调用）
 * ------------------------------------------------------------------ */

async function handlePull(request, env, url) {
  if (!requireAuth(request, env)) {
    return json({ ok: false, error: "unauthorized" }, 401);
  }
  if (!env.DB) {
    return json({ ok: false, error: "storage not configured" }, 503);
  }

  const limit = Math.min(parseInt(url.searchParams.get("limit") || "100", 10) || 100, 500);

  const subs = await env.DB.prepare(
    `SELECT id, type, form_id, post_id, data, status, created_at
     FROM submissions WHERE status = 'pending'
     ORDER BY created_at ASC LIMIT ?`
  )
    .bind(limit)
    .all();

  const rows = (subs && subs.results) || [];
  const ids = rows.map((r) => r.id);

  // 关联对应的邮件发送记录
  let emailsById = {};
  if (ids.length > 0) {
    const placeholders = ids.map(() => "?").join(",");
    const emailRes = await env.DB.prepare(
      `SELECT id, submission_id, to_addr, from_addr, subject, body, provider, status, error, attempts, created_at, sent_at
       FROM emails WHERE submission_id IN (${placeholders})`
    )
      .bind(...ids)
      .all();
    for (const e of (emailRes && emailRes.results) || []) {
      emailsById[e.submission_id] = e;
    }
  }

  const items = rows.map((r) => ({
    id: r.id,
    type: r.type,
    form_id: r.form_id,
    post_id: r.post_id,
    data: safeParse(r.data),
    created_at: r.created_at,
    email: emailsById[r.id]
      ? {
          to: emailsById[r.id].to_addr,
          from: emailsById[r.id].from_addr,
          subject: emailsById[r.id].subject,
          body: emailsById[r.id].body,
          provider: emailsById[r.id].provider,
          status: emailsById[r.id].status,
          error: emailsById[r.id].error,
          attempts: emailsById[r.id].attempts,
          sent_at: emailsById[r.id].sent_at,
        }
      : null,
  }));

  return json({ ok: true, count: items.length, items });
}

async function handleAck(request, env) {
  if (!requireAuth(request, env)) {
    return json({ ok: false, error: "unauthorized" }, 401);
  }
  if (!env.DB) {
    return json({ ok: false, error: "storage not configured" }, 503);
  }

  const body = await request.json().catch(() => null);
  const ids = body && Array.isArray(body.ids) ? body.ids : [];
  if (ids.length === 0) {
    return json({ ok: true, updated: 0 });
  }

  const now = new Date().toISOString();
  const placeholders = ids.map(() => "?").join(",");
  await env.DB.prepare(
    `UPDATE submissions SET status = 'synced', synced_at = ? WHERE id IN (${placeholders})`
  )
    .bind(now, ...ids)
    .run();
  await env.DB.prepare(
    `UPDATE emails SET synced = 1 WHERE submission_id IN (${placeholders})`
  )
    .bind(...ids)
    .run();

  return json({ ok: true, updated: ids.length });
}

function requireAuth(request, env) {
  const secret = env.WPTOCF_PULL_SECRET || "";
  if (!secret) return false;
  const auth = request.headers.get("Authorization") || "";
  const m = auth.match(/^Bearer\s+(.+)$/i);
  const token = m ? m[1] : "";
  return timingSafeEqual(token, secret);
}

/* ------------------------------------------------------------------ *
 * 邮件内容组装
 * ------------------------------------------------------------------ */

function buildNotificationEmail(env, type, formId, data) {
  const subjectTpl = env.WPTOCF_MAIL_SUBJECT || "[{type}] 新提交 - {form_id}";
  const subject = subjectTpl
    .replace(/\{type\}/g, type)
    .replace(/\{form_id\}/g, formId);

  const lines = [];
  lines.push(`类型: ${type}`);
  lines.push(`表单: ${formId}`);
  lines.push("");
  for (const [k, v] of Object.entries(data)) {
    lines.push(`${k}: ${typeof v === "object" ? JSON.stringify(v) : v}`);
  }
  const text = lines.join("\n");

  return {
    from: env.WPTOCF_MAIL_FROM || "",
    to: env.WPTOCF_MAIL_TO || "",
    subject,
    text,
  };
}

/* ------------------------------------------------------------------ *
 * 邮件后端：AWS SES（SigV4）
 * ------------------------------------------------------------------ */

async function sendViaSES(env, mail) {
  const region = env.WPTOCF_SES_REGION;
  const accessKeyId = env.WPTOCF_SES_ACCESS_KEY_ID;
  const secretAccessKey = env.WPTOCF_SES_SECRET_ACCESS_KEY;
  if (!region || !accessKeyId || !secretAccessKey) {
    throw new Error("SES credentials incomplete");
  }

  const host = `email.${region}.amazonaws.com`;
  const path = "/v2/email/outbound-emails";
  const payload = JSON.stringify({
    FromEmailAddress: mail.from,
    Destination: { ToAddresses: splitAddrs(mail.to) },
    Content: {
      Simple: {
        Subject: { Data: mail.subject, Charset: "UTF-8" },
        Body: { Text: { Data: mail.text, Charset: "UTF-8" } },
      },
    },
  });

  const headers = await signAwsV4({
    method: "POST",
    host,
    path,
    region,
    service: "ses",
    accessKeyId,
    secretAccessKey,
    body: payload,
    contentType: "application/json",
  });

  const resp = await fetch(`https://${host}${path}`, {
    method: "POST",
    headers,
    body: payload,
  });

  if (!resp.ok) {
    const t = await resp.text().catch(() => "");
    throw new Error(`SES HTTP ${resp.status}: ${t.slice(0, 300)}`);
  }
}

/**
 * 最小化 AWS SigV4 签名（用于 SES v2 REST 端点）
 */
async function signAwsV4({ method, host, path, region, service, accessKeyId, secretAccessKey, body, contentType }) {
  const now = new Date();
  const amzDate = now.toISOString().replace(/[:-]|\.\d{3}/g, ""); // YYYYMMDDTHHMMSSZ
  const dateStamp = amzDate.slice(0, 8);

  const payloadHash = await sha256Hex(body);
  const canonicalHeaders =
    `content-type:${contentType}\n` +
    `host:${host}\n` +
    `x-amz-content-sha256:${payloadHash}\n` +
    `x-amz-date:${amzDate}\n`;
  const signedHeaders = "content-type;host;x-amz-content-sha256;x-amz-date";

  const canonicalRequest = [
    method,
    path,
    "", // query string
    canonicalHeaders,
    signedHeaders,
    payloadHash,
  ].join("\n");

  const algorithm = "AWS4-HMAC-SHA256";
  const scope = `${dateStamp}/${region}/${service}/aws4_request`;
  const stringToSign = [
    algorithm,
    amzDate,
    scope,
    await sha256Hex(canonicalRequest),
  ].join("\n");

  const kDate = await hmacRaw(encode(`AWS4${secretAccessKey}`), dateStamp);
  const kRegion = await hmacRaw(kDate, region);
  const kService = await hmacRaw(kRegion, service);
  const kSigning = await hmacRaw(kService, "aws4_request");
  const signature = toHex(await hmacRaw(kSigning, stringToSign));

  const authorization =
    `${algorithm} Credential=${accessKeyId}/${scope}, ` +
    `SignedHeaders=${signedHeaders}, Signature=${signature}`;

  return {
    "Content-Type": contentType,
    "X-Amz-Content-Sha256": payloadHash,
    "X-Amz-Date": amzDate,
    Authorization: authorization,
  };
}

/* ------------------------------------------------------------------ *
 * 邮件后端：通用 HTTP API（Resend 风格）
 * ------------------------------------------------------------------ */

async function sendViaHTTP(env, mail) {
  const endpoint = env.WPTOCF_HTTP_ENDPOINT;
  const apiKey = env.WPTOCF_HTTP_API_KEY;
  if (!endpoint) throw new Error("HTTP endpoint not configured");

  const resp = await fetch(endpoint, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      ...(apiKey ? { Authorization: `Bearer ${apiKey}` } : {}),
    },
    body: JSON.stringify({
      from: mail.from,
      to: splitAddrs(mail.to),
      subject: mail.subject,
      text: mail.text,
    }),
  });

  if (!resp.ok) {
    const t = await resp.text().catch(() => "");
    throw new Error(`HTTP mail ${resp.status}: ${t.slice(0, 300)}`);
  }
}

/* ------------------------------------------------------------------ *
 * 邮件后端：SMTP（cloudflare:sockets，支持 587 STARTTLS / 465 TLS）
 * ------------------------------------------------------------------ */

async function sendViaSMTP(env, mail) {
  const host = env.WPTOCF_SMTP_HOST;
  const port = parseInt(env.WPTOCF_SMTP_PORT || "587", 10);
  const user = env.WPTOCF_SMTP_USER || "";
  const pass = env.WPTOCF_SMTP_PASS || "";
  if (!host) throw new Error("SMTP host not configured");

  const implicitTls = port === 465;
  let socket = connect(
    { hostname: host, port },
    implicitTls ? { secureTransport: "on" } : { secureTransport: "starttls" }
  );

  const enc = new TextEncoder();
  const dec = new TextDecoder();
  let writer = socket.writable.getWriter();
  let reader = socket.readable.getReader();

  async function readReply() {
    // 读取直到出现终止行 "NNN "（多行响应中间行为 "NNN-"）
    let buf = "";
    while (true) {
      const { value, done } = await reader.read();
      if (done) break;
      buf += dec.decode(value, { stream: true });
      const lines = buf.split(/\r?\n/).filter((l) => l.length > 0);
      const last = lines[lines.length - 1] || "";
      if (/^\d{3} /.test(last)) break;
    }
    // 取最后一个状态行的返回码
    const statusLines = buf.split(/\r?\n/).filter((l) => /^\d{3}/.test(l));
    const finalLine = statusLines.length ? statusLines[statusLines.length - 1] : buf;
    return { code: parseInt(finalLine.slice(0, 3), 10), text: buf };
  }
  async function cmd(line, okCodes) {
    if (line !== null) await writer.write(enc.encode(line + "\r\n"));
    const reply = await readReply();
    if (okCodes && !okCodes.includes(reply.code)) {
      throw new Error(`SMTP ${line || "greeting"} -> ${reply.text.trim().slice(0, 200)}`);
    }
    return reply;
  }

  try {
    await cmd(null, [220]); // greeting
    await cmd("EHLO wp-to-cf", [250]);

    // STARTTLS：升级连接后需重新获取读写器并重新 EHLO
    if (!implicitTls) {
      await cmd("STARTTLS", [220]);
      reader.releaseLock();
      writer.releaseLock();
      socket = socket.startTls();
      writer = socket.writable.getWriter();
      reader = socket.readable.getReader();
      await cmd("EHLO wp-to-cf", [250]);
    }

    if (user && pass) {
      await cmd("AUTH LOGIN", [334]);
      await cmd(btoa(user), [334]);
      await cmd(btoa(pass), [235]);
    }

    await cmd(`MAIL FROM:<${extractAddr(mail.from)}>`, [250]);
    for (const rcpt of splitAddrs(mail.to)) {
      await cmd(`RCPT TO:<${extractAddr(rcpt)}>`, [250, 251]);
    }
    await cmd("DATA", [354]);

    const headers =
      `From: ${mail.from}\r\n` +
      `To: ${mail.to}\r\n` +
      `Subject: ${encodeHeader(mail.subject)}\r\n` +
      `MIME-Version: 1.0\r\n` +
      `Content-Type: text/plain; charset=UTF-8\r\n` +
      `Content-Transfer-Encoding: 8bit\r\n\r\n`;
    // 先统一换行为 CRLF，再做点填充（行首单独的 "." → ".."）
    const body = mail.text.replace(/\r?\n/g, "\r\n").replace(/\r\n\./g, "\r\n..");
    await writer.write(enc.encode(headers + body + "\r\n.\r\n"));
    await cmd(null, [250]);
    await cmd("QUIT", [221]);
  } finally {
    try { writer.releaseLock(); } catch (_) {}
    try { await socket.close(); } catch (_) {}
  }
}

/* ------------------------------------------------------------------ *
 * 工具函数
 * ------------------------------------------------------------------ */

async function readBody(request) {
  const ct = (request.headers.get("Content-Type") || "").toLowerCase();
  const raw = await request.arrayBuffer();
  if (raw.byteLength > MAX_BODY_BYTES) return null;

  try {
    if (ct.includes("application/json")) {
      const obj = JSON.parse(new TextDecoder().decode(raw));
      return flatten(obj);
    }
    // application/x-www-form-urlencoded 或 multipart
    if (ct.includes("multipart/form-data")) {
      const req2 = new Request(request.url, { method: "POST", headers: request.headers, body: raw });
      const form = await req2.formData();
      const out = {};
      for (const [k, v] of form.entries()) {
        out[k] = typeof v === "string" ? v : `[file:${v.name || ""}]`;
      }
      return out;
    }
    const params = new URLSearchParams(new TextDecoder().decode(raw));
    const out = {};
    for (const [k, v] of params.entries()) out[k] = v;
    return out;
  } catch (_) {
    return null;
  }
}

function flatten(obj) {
  const out = {};
  for (const [k, v] of Object.entries(obj || {})) {
    out[k] = v;
  }
  return out;
}

async function verifyTurnstile(secret, token, request) {
  if (!token) return false;
  const ip = request.headers.get("CF-Connecting-IP") || "";
  const body = new URLSearchParams();
  body.set("secret", secret);
  body.set("response", token);
  if (ip) body.set("remoteip", ip);
  try {
    const resp = await fetch("https://challenges.cloudflare.com/turnstile/v0/siteverify", {
      method: "POST",
      body,
    });
    const data = await resp.json();
    return !!(data && data.success);
  } catch (_) {
    return false;
  }
}

function json(obj, status = 200) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { "Content-Type": "application/json; charset=utf-8" },
  });
}

function safeParse(s) {
  try {
    return JSON.parse(s);
  } catch (_) {
    return s;
  }
}

function splitAddrs(s) {
  return String(s || "")
    .split(",")
    .map((x) => x.trim())
    .filter((x) => x.length > 0);
}

function extractAddr(s) {
  const m = String(s || "").match(/<([^>]+)>/);
  return m ? m[1] : String(s || "").trim();
}

function encodeHeader(s) {
  // 若含非 ASCII，使用 RFC 2047 编码
  if (/^[\x00-\x7F]*$/.test(s)) return s;
  return `=?UTF-8?B?${btoa(unescape(encodeURIComponent(s)))}?=`;
}

function timingSafeEqual(a, b) {
  if (typeof a !== "string" || typeof b !== "string" || a.length !== b.length) {
    return false;
  }
  let diff = 0;
  for (let i = 0; i < a.length; i++) {
    diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return diff === 0;
}

const _enc = new TextEncoder();
function encode(s) {
  return _enc.encode(s);
}

async function sha256Hex(str) {
  const digest = await crypto.subtle.digest("SHA-256", typeof str === "string" ? encode(str) : str);
  return toHex(new Uint8Array(digest));
}

async function hmacRaw(keyBytes, msg) {
  const key = await crypto.subtle.importKey(
    "raw",
    keyBytes,
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"]
  );
  const sig = await crypto.subtle.sign("HMAC", key, encode(msg));
  return new Uint8Array(sig);
}

function toHex(bytes) {
  let out = "";
  for (let i = 0; i < bytes.length; i++) {
    out += bytes[i].toString(16).padStart(2, "0");
  }
  return out;
}
