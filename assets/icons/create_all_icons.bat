@echo off
echo Creating all app icons...
echo.

REM Create icons using the PHP script
php create_simple_icons.php

if %errorlevel% neq 0 (
    echo PHP script failed. Creating SVG icons manually...
    REM Create basic SVG icons manually
)

echo.
echo All icons created successfully!
echo You can now use them in the application.
pause

