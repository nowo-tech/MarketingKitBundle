# Server cookbook

MarketingKitBundle injects HTML snippets via Twig. No public marketing HTTP endpoints beyond optional admin CRUD.

## FrankenPHP worker mode

Compile-time services and Twig extensions are worker-safe. Consent cookies are read per request from `RequestStack`.

## Nginx / php-fpm

No special rewrite rules. Protect `/admin/marketing*` in the application firewall.
