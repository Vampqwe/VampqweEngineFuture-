# Запуск встроенного PHP-сервера для разработки
$port = 8000
$publicPath = "public"

Write-Host "🚀 Запуск VampqweEngineFuture на http://localhost:$port" -ForegroundColor Green
Write-Host "Нажми Ctrl+C для остановки" -ForegroundColor Yellow

php -S localhost:$port -t $publicPath