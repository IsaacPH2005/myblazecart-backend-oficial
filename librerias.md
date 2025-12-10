composer require laravel/sanctum
php artisan vendor:publish --tag="sanctum-config"

composer require laraveles/spanish
php artisan vendor:publish --tag=lang

composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"