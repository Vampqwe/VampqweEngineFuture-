# Запуск встроенного PHP-сервера для разработки
$port = 8000
$publicPath = "public"

Write-Host "🚀 Запуск VampqweEngineFuture на http://localhost:$port" -ForegroundColor Green
Write-Host "Нажми Ctrl+C для остановки" -ForegroundColor Yellow

# public/router.php — обязателен: без него php -S не применяет правила фронт-контроллера
php -S localhost:$port -t $publicPath $publicPath/router.php