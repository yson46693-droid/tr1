# أيقونات التطبيق

هذا المجلد يحتوي على أيقونات التطبيق بأحجام مختلفة لدعم PWA (Progressive Web App) والأجهزة المختلفة.

## الأحجام المتوفرة

- `icon-72x72.png` - للأيقونات الصغيرة
- `icon-96x96.png` - للأيقونات المتوسطة
- `icon-128x128.png` - للأيقونات الكبيرة
- `icon-144x144.png` - لـ Windows Phone
- `icon-152x152.png` - لـ iPad
- `icon-192x192.png` - لـ Android
- `icon-384x384.png` - لـ Android (كبير)
- `icon-512x512.png` - لـ Splash Screen
- `apple-touch-icon.png` (180x180) - لـ iOS
- `favicon.png` (32x32) - Favicon المتصفح

## كيفية إنشاء الأيقونات

### الطريقة 1: استخدام PHP (يتطلب GD)

```bash
php assets/icons/create_png_icons.php
```

### الطريقة 2: استخدام SVG ثم التحويل

```bash
php assets/icons/generate_icons.php
```

ثم استخدم محول SVG إلى PNG:
- ImageMagick: `convert icon.svg icon.png`
- Inkscape: `inkscape icon.svg --export-png=icon.png`
- أو أي محول online

### الطريقة 3: استخدام أداة تصميم

يمكنك استخدام أي أداة تصميم (Photoshop, GIMP, Figma) لإنشاء أيقونة واحدة ثم تصديرها بجميع الأحجام.

## المتطلبات

- الأيقونة يجب أن تكون مربعة
- الألوان الرئيسية: `#1e3a5f` (أزرق داكن)
- يجب أن تكون واضحة على خلفيات مختلفة
- يفضل أن تكون بسيطة وواضحة في الأحجام الصغيرة

## التحديث

عند تحديث الأيقونات، تأكد من:
1. تحديث جميع الأحجام
2. تحديث `manifest.json`
3. تحديث `templates/header.php`
4. مسح ذاكرة التخزين المؤقت للمتصفح
