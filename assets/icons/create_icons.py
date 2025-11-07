#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
سكريبت لإنشاء أيقونات التطبيق بأحجام مختلفة
"""

import os

# الألوان
primary_color = "#1e3a5f"
secondary_color = "#2c5282"
text_color = "#FFFFFF"

# الأحجام المطلوبة
sizes = [16, 32, 72, 96, 128, 144, 152, 180, 192, 384, 512]

def create_icon_svg(size):
    """إنشاء أيقونة SVG بحجم معين"""
    svg = f'''<?xml version="1.0" encoding="UTF-8"?>
<svg width="{size}" height="{size}" xmlns="http://www.w3.org/2000/svg">
    <defs>
        <linearGradient id="grad{size}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:#1e3a5f;stop-opacity:1" />
            <stop offset="100%" style="stop-color:#2c5282;stop-opacity:1" />
        </linearGradient>
    </defs>
    <rect width="{size}" height="{size}" rx="{size * 0.18}" fill="url(#grad{size})"/>
    <circle cx="{size / 2}" cy="{size * 0.45}" r="{size * 0.32}" fill="rgba(255,255,255,0.15)"/>
    <text x="{size / 2}" y="{size * 0.62}" font-family="Arial, 'Segoe UI', sans-serif" font-size="{size * 0.45}" font-weight="bold" fill="{text_color}" text-anchor="middle" dominant-baseline="middle">ن</text>
    <circle cx="{size * 0.25}" cy="{size * 0.22}" r="{size * 0.04}" fill="{text_color}" opacity="0.5"/>
    <circle cx="{size * 0.75}" cy="{size * 0.22}" r="{size * 0.04}" fill="{text_color}" opacity="0.5"/>
</svg>'''
    return svg

def main():
    """الدالة الرئيسية"""
    # الحصول على مسار المجلد الحالي
    icons_dir = os.path.dirname(os.path.abspath(__file__))
    
    print("بدء إنشاء الأيقونات...\n")
    
    # إنشاء أيقونات لكل حجم
    for size in sizes:
        svg = create_icon_svg(size)
        filename = os.path.join(icons_dir, f'icon-{size}x{size}.svg')
        
        with open(filename, 'w', encoding='utf-8') as f:
            f.write(svg)
        
        print(f"✓ تم إنشاء: icon-{size}x{size}.svg")
    
    # إنشاء favicon.svg
    favicon = create_icon_svg(32)
    favicon_path = os.path.join(icons_dir, 'favicon.svg')
    with open(favicon_path, 'w', encoding='utf-8') as f:
        f.write(favicon)
    print(f"✓ تم إنشاء: favicon.svg")
    
    # إنشاء apple-touch-icon.svg
    apple_icon = create_icon_svg(180)
    apple_path = os.path.join(icons_dir, 'apple-touch-icon.svg')
    with open(apple_path, 'w', encoding='utf-8') as f:
        f.write(apple_icon)
    print(f"✓ تم إنشاء: apple-touch-icon.svg")
    
    print("\n✓ تم إنشاء جميع الأيقونات بنجاح!")
    print("\nملاحظة: يمكنك تحويل SVG إلى PNG باستخدام:")
    print("- ImageMagick: convert icon.svg icon.png")
    print("- أو استخدام محول online: https://convertio.co/svg-png/")

if __name__ == '__main__':
    main()

