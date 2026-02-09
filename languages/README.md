# WordPress to Cloudflare - Language Files

## Compiling Translation Files

To compile the `.po` file to `.mo` format, you need to use `msgfmt` (part of gettext tools).

### On Linux/Mac:
```bash
msgfmt -o wp-to-cf-zh_CN.mo wp-to-cf-zh_CN.po
```

### On Windows:
1. Install Poedit: https://poedit.net/
2. Open `wp-to-cf-zh_CN.po` in Poedit
3. Click "Save" - it will automatically generate the `.mo` file

### Online Tool:
You can also use online PO to MO converters:
- https://po2mo.net/

## Adding New Translations

1. Edit `wp-to-cf-zh_CN.po`
2. Add your translations
3. Compile to `.mo` format
4. Upload both `.po` and `.mo` files to the `languages/` directory

## Current Translations

- English (en_US) - Default
- Chinese Simplified (zh_CN) - Available
