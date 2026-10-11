<div dir="rtl">

# Request Tracer

[![الإصدار](https://img.shields.io/packagist/v/your-vendor/laravel-request-tracer.svg)](https://packagist.org/packages/your-vendor/laravel-request-tracer)
[![الاختبارات](https://github.com/your-vendor/laravel-request-tracer/actions/workflows/ci.yml/badge.svg)](https://github.com/your-vendor/laravel-request-tracer/actions/workflows/ci.yml)
[![الرخصة: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**استبدل `dd()` بتتبّع كامل للطلب.**

عندما يتصرّف طلب بشكل غريب، يعرض لك `dd()` قيمة واحدة في مكان واحد ثم يقتل الطلب. أما Request Tracer فيسجّل **الطلب كاملاً** على شكل شجرة، ويتيح لك النظر داخل كل خطوة:

- **المسار (Flow):** الـ middleware والـ controller والتحقق (validation) والخدمات والاستعلامات واستدعاءات HTTP والأحداث والـ jobs والاستثناءات، مع شريط زمني لكل خطوة.
- **المدخلات والمخرجات** لكل خطوة، بنفس تنسيق `dd()`.
- **داخل الكود:** سطراً بسطر، أي شرط كان صحيحاً أو خاطئاً، وأي كتلة نُفّذت، وماذا كانت قيم المتغيرات، مع أرقام الأسطر الأصلية وزر إعادة التشغيل (Replay).
- **قاعدة البيانات:** كل جدول كتب فيه الطلب، مع الصف قبل التغيير وبعده.
- **المشاكل:** الاستثناءات التي تم التقاطها، واستعلامات N+1، والاستعلامات المكرّرة أو البطيئة، والخطوات البطيئة، واستدعاءات HTTP الفاشلة.

صُمّمت الحزمة لتبقى مثبّتة في بيئة الإنتاج: **لا يُسجَّل شيء ما لم تطلب ذلك** (ترويسة موقّعة، أو مستخدم تحت المراقبة، أو عيّنة صغيرة)، وتُخفى الأسرار قبل التخزين، ولوحة التحكم مغلقة حتى تفتح لها صلاحية (Gate)، وأي عطل داخل المتتبّع لا يكسر الطلب أبداً.

## البدء السريع

```bash
# 1. التثبيت
composer require your-vendor/laravel-request-tracer

# 2. نشر الإعدادات والـ migrations ولوحة التحكم، ثم تنفيذ الـ migrate
php artisan request-tracer:install --migrate

# 3. استخدم تطبيقك ثم افتح لوحة التحكم (في البيئة المحلية لا تحتاج إلى تسجيل دخول)
# http://localhost:8000/request-tracer
```

لترى **خدماتك أنت** داخل التتبّع، اكتب مساحات الأسماء (namespaces) في `config/request-tracer.php`:

```php
'proxies' => ['namespaces' => ['App\Services', 'App\Domain\*']],
'deep_trace' => ['namespaces' => ['App\Services\OrderService']],   // سطراً بسطر لهذا الصنف
```

في الإنتاج، حدّد من يحق له فتح لوحة التحكم، ثم سجّل طلباً واحداً بترويسة موقّعة:

```php
Gate::define('viewRequestTracer', fn ($user) => in_array($user->email, ['ops@example.com']));
```

```bash
curl -H "X-Request-Tracer: $(php artisan request-tracer:token --ttl=300 | head -1)" https://shop.example.com/orders
```

يمكنك أيضاً تجربة اللوحة ببيانات تجريبية ودون خادم خلفي: `npm ci && npm run dev`.

## المتطلبات

PHP 8.1 أو أحدث، وLaravel 10 أو 11 أو 12 أو 13، وقاعدة بيانات MySQL / MariaDB أو PostgreSQL أو SQLite لحفظ التتبّعات. تعمل مع Octane ومع عمّال الـ queue.

## التوثيق

التوثيق الكامل بالإنجليزية في مجلد [docs](docs/01-introduction.md): المقدمة، التثبيت، البدء السريع، مرجع الإعدادات، آلية الالتقاط، التتبّع العميق (Deep trace)، التشغيل في الإنتاج، التطبيقات الكبيرة وأنظمة ERP، استخدام لوحة التحكم، الـ jobs والأوامر والمهام المجدولة، التوسعة، المقارنة مع الأدوات الأخرى، وحل المشاكل.

## ما التكلفة؟

حوالي 25 ميكروثانية للطلب الذي لم يطلب أحد تسجيله. وللطلب المسجَّل (47 خطوة و15 استعلاماً في المثال المقاس) تضاف نحو 5 إلى 7 مللي ثانية قبل إرسال الاستجابة، ونحو 7 إلى 10 مللي ثانية بعد إرسالها. طريقة القياس والتحفّظات في [docs/benchmarks.md](docs/benchmarks.md).

## حدود يجب معرفتها

لا تستطيع الـ proxies رؤية `new` ولا الأصناف `final` ولا الدوال الخاصة (private) أو الساكنة (static). التتبّع العميق يترجم نسخة من الأصناف التي تسمّيها ولا يؤثر إلا في الأصناف التي تُحمَّل بعد بدايته. إخفاء الأسرار يعتمد على الأسماء. والحزمة ما زالت في الإصدارات `0.x`.

## الرخصة

MIT. انظر [LICENSE](LICENSE). English: [README.md](README.md)

</div>
