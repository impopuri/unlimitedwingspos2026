@echo off
setlocal
pushd "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
    echo PHP was not found. Install PHP 8.2 or newer and add php.exe to PATH.
    pause
    exit /b 1
)

php -d extension=php_fileinfo.dll -r "exit(extension_loaded('fileinfo') && extension_loaded('pdo_sqlite') ? 0 : 1);"
if errorlevel 1 (
    echo PHP must provide the fileinfo and pdo_sqlite extensions.
    echo Confirm that php_fileinfo.dll exists in PHP's ext directory.
    pause
    exit /b 1
)

if not exist ".env" copy ".env.example" ".env" >nul
if not exist "database\database.sqlite" type nul > "database\database.sqlite"

php -d extension=php_fileinfo.dll artisan -V >nul
if errorlevel 1 (
    echo Laravel dependencies are missing or the application could not start.
    echo Run: composer install
    pause
    exit /b 1
)

findstr /R /C:"^APP_KEY=$" ".env" >nul
if not errorlevel 1 (
    php -d extension=php_fileinfo.dll artisan key:generate --force
    if errorlevel 1 goto :failed
)

php -d extension=php_fileinfo.dll artisan migrate --seed --force
if errorlevel 1 goto :failed

netstat -ano | findstr /R /C:":8002 .*LISTENING" >nul
if errorlevel 1 (
    start "ETIVAC WINGS POS server" /D "%CD%" cmd /k "php -d extension=php_fileinfo.dll artisan serve --host=0.0.0.0 --port=8002"
    timeout /t 2 /nobreak >nul
) else (
    echo Port 8002 is already listening. Opening the existing service.
)

start "" "http://localhost:8002/posetivacwingspos"
echo.
echo ETIVAC WINGS POS is opening in your browser.
echo Keep the server window open while using the application.
popd
exit /b 0

:failed
echo Application setup failed. Review the error above.
pause
popd
exit /b 1
