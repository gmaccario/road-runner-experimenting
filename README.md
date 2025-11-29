# Road Runner experimenting

This repo contains some experiments with the [RoadRunner](https://roadrunner.dev/): a high-performance PHP application server, load-balancer, and process manager written in Golang.

### How to run the episodes
```
cd "episodes/0 - First Contact"
docker-compose down -v
docker-compose build --no-cache
docker-compose up
```

Want to try another episode? Change the directory and run the commands again.

# Complete RoadRunner Setup Guide - From Zero to Production

## Table of Contents
- [What is RoadRunner?](#what-is-roadrunner)
- [Episode 0: Pure PHP Application](#episode-0-pure-php-application-with-roadrunner)
- [Episode 1: Symfony Application](#episode-1-symfony-application-with-roadrunner)
- [Common Issues & Solutions](#common-issues--solutions)
- [Performance Testing](#performance-testing)
- [Production Recommendations](#production-recommendations)
- [Resources](#resources)

---

## What is RoadRunner?

RoadRunner is a high-performance PHP application server written in Go that keeps your PHP application loaded in memory and reuses worker processes across multiple requests.

### Key Differences: Nginx+PHP-FPM vs RoadRunner

**Traditional PHP-FPM:**
```
Request → Nginx → PHP-FPM → New PHP Process
                           ↓
                  Load Framework → Execute → Terminate
```
Every request bootstraps the entire application from scratch.

**RoadRunner:**
```
Request → RoadRunner → Existing PHP Worker (already bootstrapped)
                      ↓
                Execute → Return to Pool (stay alive)
```
Workers persist, framework stays in memory.

### Performance Benefits

- **Laravel/Symfony bootstrap:** ~50-100ms with PHP-FPM → ~5-10ms with RoadRunner
- **Potential improvement:** 5-10x faster for framework-heavy applications
- **Throughput:** 3-5x more requests per second

### Important Gotchas

1. **State persists between requests** - Static variables and singletons don't reset
2. **Memory leaks accumulate** - Use `max_jobs` to periodically restart workers
3. **File changes require worker restart** - Unlike PHP-FPM where changes are immediate
4. **Global state requires careful management** - What works in one request affects the next

---

## Episode 0: Pure PHP Application with RoadRunner

### Project Structure
```
roadrunner-test/
├── Dockerfile
├── docker-compose.yml
└── app/
    ├── .rr.yaml
    ├── composer.json
    ├── worker.php
    └── vendor/ (created after composer install)
```

### Step-by-Step Setup

#### 1. Create composer.json

**`app/composer.json`:**
```json
{
    "require": {
        "php": "^8.4",
        "spiral/roadrunner-worker": "^3.0",
        "spiral/roadrunner-http": "^3.0",
        "nyholm/psr7": "^1.5"
    }
}
```

#### 2. Create the PHP Worker

**`app/worker.php`:**
```php
<?php

use Spiral\RoadRunner\Worker;
use Spiral\RoadRunner\Http\PSR7Worker;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Factory\Psr17Factory;

require __DIR__.'/vendor/autoload.php';

$worker = Worker::create();
$factory = new Psr17Factory();
$psr7 = new PSR7Worker($worker, $factory, $factory, $factory);

while ($request = $psr7->waitRequest()) {
    try {
        $response = new Response(200, [], 'Hello from RoadRunner!');
        $psr7->respond($response);
    } catch (\Throwable $e) {
        $psr7->getWorker()->error((string)$e);
    }
}
```

#### 3. Create RoadRunner Configuration

**`app/.rr.yaml`:**
```yaml
version: "3"

server:
  command: "php worker.php"

http:
  address: 0.0.0.0:8080
  pool:
    num_workers: 4
    max_jobs: 0

logs:
  mode: development
  level: debug
```

#### 4. Create Dockerfile

**`Dockerfile`:**
```dockerfile
FROM ghcr.io/roadrunner-server/roadrunner:2024 as roadrunner
FROM php:8.4-alpine

RUN --mount=type=bind,from=mlocati/php-extension-installer:2,source=/usr/bin/install-php-extensions,target=/usr/local/bin/install-php-extensions \
    install-php-extensions @composer-2 opcache zip intl sockets protobuf

COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr

EXPOSE 8080/tcp
WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY ./app/composer.* .
RUN composer install --optimize-autoloader --no-dev

COPY ./app .

CMD ["rr", "serve"]
```

#### 5. Create docker-compose.yml

**`docker-compose.yml`:**
```yaml
services:
  roadrunner:
    build: .
    ports:
      - "8080:8080"
    volumes:
      - ./app:/app
      - vendor:/app/vendor

volumes:
  vendor:
```

#### 6. Run It!
```bash
docker-compose up --build
```

Visit `http://localhost:8080` - you should see "Hello from RoadRunner!"

---

## Episode 1: Symfony Application with RoadRunner

### Critical Learning: Use the Official Bundle!

**DO NOT** create a custom `worker.php` for Symfony. Use `baldinof/roadrunner-bundle` which handles all the complexity.

### Project Structure
```
symfony-roadrunner/
├── Dockerfile
├── docker-compose.yml
└── app/
    ├── .rr.yaml
    ├── composer.json
    ├── symfony.lock
    ├── bin/
    │   ├── console
    │   └── roadrunner-worker
    ├── config/
    │   ├── bundles.php
    │   └── packages/
    │       └── baldinof_road_runner.yaml
    ├── src/
    ├── public/
    └── vendor/
```

### Step-by-Step Setup

#### 1. Install Symfony
```bash
# Using Docker Composer
docker run --rm -v $(pwd):/app composer create-project symfony/skeleton:"7.2.*" app

# OR using local Composer
composer create-project symfony/skeleton:"7.2.*" app
```

#### 2. Install RoadRunner Bundle and Dependencies

**Update `app/composer.json`** to include:
```json
{
    "require": {
        "php": "^8.4",
        "symfony/console": "7.2.*",
        "symfony/dotenv": "7.2.*",
        "symfony/flex": "^2",
        "symfony/framework-bundle": "7.2.*",
        "symfony/runtime": "7.2.*",
        "symfony/yaml": "7.2.*",
        "baldinof/roadrunner-bundle": "^4.0",
        "spiral/roadrunner-http": "^3.0",
        "nyholm/psr7": "^1.5",
        "symfony/psr-http-message-bridge": "^7.0"
    }
}
```

Then install:
```bash
cd app
composer install

# OR using Docker
docker run --rm -v "$(pwd)/app:/app" -w /app composer install --ignore-platform-reqs
```

#### 3. Register the Bundle

**`app/config/bundles.php`:**
```php
<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Baldinof\RoadRunnerBundle\BaldinofRoadRunnerBundle::class => ['all' => true],
];
```

#### 4. Create RoadRunner Worker Script

**`app/bin/roadrunner-worker`:**
```php
#!/usr/bin/env php
<?php

use App\Kernel;
use Baldinof\RoadRunnerBundle\Runtime\Runner;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    $kernel = new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
    return new Runner($kernel, $context['APP_ENV']);
};
```

Make it executable:
```bash
chmod +x app/bin/roadrunner-worker
```

#### 5. Configure the Bundle (Optional for Dev)

**`app/config/packages/baldinof_road_runner.yaml`:**
```yaml
baldinof_road_runner:
    should_reboot_kernel: true
    middlewares:
        - 'Baldinof\RoadRunnerBundle\Http\Middleware\SentryMiddleware'
```

#### 6. Create RoadRunner Configuration

**For Development (`app/.rr.yaml`):**
```yaml
version: "3"

rpc:
  listen: tcp://127.0.0.1:6001

server:
  command: "php bin/roadrunner-worker"
  relay: pipes
  relay_timeout: 60s

http:
  address: 0.0.0.0:8080
  pool:
    num_workers: 1
    max_jobs: 1  # Restart after each request for live reload
    supervisor:
      watch_tick: 1s
      ttl: 0s
      idle_ttl: 10s
      exec_ttl: 60s

logs:
  mode: development
  level: debug
```

**For Production:**
```yaml
version: "3"

rpc:
  listen: tcp://127.0.0.1:6001

server:
  command: "php bin/roadrunner-worker"

http:
  address: 0.0.0.0:8080
  pool:
    num_workers: 4
    max_jobs: 0

logs:
  mode: production
  level: error
```

#### 7. Create Dockerfile

**`Dockerfile`:**
```dockerfile
FROM ghcr.io/roadrunner-server/roadrunner:2024 as roadrunner
FROM php:8.4-alpine

RUN --mount=type=bind,from=mlocati/php-extension-installer:2,source=/usr/bin/install-php-extensions,target=/usr/local/bin/install-php-extensions \
    install-php-extensions @composer-2 opcache zip intl sockets protobuf pdo_mysql

COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr

EXPOSE 8080/tcp
WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ENV=prod

COPY ./app/composer.* ./app/symfony.lock ./
RUN composer install --optimize-autoloader --no-dev --no-scripts

COPY ./app .

CMD ["rr", "serve"]
```

#### 8. Create docker-compose.yml

**`docker-compose.yml`:**
```yaml
services:
  roadrunner:
    build: .
    ports:
      - "8080:8080"
    volumes:
      - ./app:/app
      - vendor:/app/vendor
    environment:
      - APP_ENV=dev
      - APP_DEBUG=1
    working_dir: /app

volumes:
  vendor:
```

#### 9. Create a Test Controller (Optional)

**`app/src/Controller/TestController.php`:**
```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class TestController extends AbstractController
{
    #[Route('/test', name: 'app_test')]
    public function index(): JsonResponse
    {
        return $this->json([
            'message' => 'Hello from Symfony + RoadRunner!',
            'timestamp' => time(),
        ]);
    }
}
```

#### 10. Run It!
```bash
docker-compose up --build
```

Visit:
- `http://localhost:8080` - Symfony welcome page
- `http://localhost:8080/test` - Your test endpoint

---

## Common Issues & Solutions

### Issue 1: "Missing RR worker implementation for dev mode"

**Solution:** Either:
- Set `APP_ENV=prod` in docker-compose.yml
- OR use `max_jobs: 1` in `.rr.yaml` for dev mode with auto-restart

### Issue 2: "goridge_frame_receive: validation failed"

**Cause:** PHP outputting HTML/errors during worker initialization

**Solution:** Use the official `baldinof/roadrunner-bundle` which handles this properly

### Issue 3: Container keeps exiting

**Debug steps:**
```bash
# Check if worker runs manually
docker-compose run --rm roadrunner php /app/bin/roadrunner-worker

# Check logs
docker-compose logs roadrunner
```

### Issue 4: Changes not reflected

**Cause:** Workers are persistent

**Solutions:**
- **Dev mode:** Use `max_jobs: 1` in `.rr.yaml`
- **Manual restart:** `docker-compose restart roadrunner`

### Issue 5: ext-sockets missing when installing with local Composer

**Solution:** Use `--ignore-platform-reqs` flag:
```bash
composer require package-name --ignore-platform-reqs
```

### Issue 6: "You have requested a non-existent service"

**Cause:** Bundle not registered in `config/bundles.php`

**Solution:** Add the bundle to your bundles.php file (see Step 3 in Symfony setup)

### Issue 7: "bin/roadrunner-worker not found"

**Cause:** Worker file doesn't exist

**Solution:** Create the file manually (see Step 4 in Symfony setup) and make it executable

---

## Key Differences Between Pure PHP and Symfony Setup

| Aspect | Pure PHP | Symfony |
|--------|----------|---------|
| Worker file | Custom `worker.php` with PSR-7 | `bin/roadrunner-worker` using bundle |
| Dependencies | `spiral/roadrunner-*`, `nyholm/psr7` | Add `baldinof/roadrunner-bundle`, `symfony/psr-http-message-bridge` |
| Configuration | Simple `.rr.yaml` | `.rr.yaml` + bundle config in `config/packages/` |
| Bundle registration | N/A | Must register in `config/bundles.php` |
| Complexity | Low - direct PSR-7 handling | Medium - requires proper bundle setup |

---

## Performance Testing

To verify RoadRunner is actually working and see performance gains:
```bash
# Install Apache Bench
brew install httpd  # macOS
apt-get install apache2-utils  # Ubuntu/Debian

# Test with 1000 requests, 10 concurrent
ab -n 1000 -c 10 http://localhost:8080/

# Compare with traditional PHP-FPM setup
```

Expected results: 3-10x improvement in requests/second with RoadRunner.

---

## Production Recommendations

1. **Use production mode:** `APP_ENV=prod`, `APP_DEBUG=0`
2. **Set max_jobs:** Restart workers periodically to prevent memory leaks (e.g., `max_jobs: 1000`)
3. **Monitor memory:** Use `supervisor.max_worker_memory` in `.rr.yaml`
4. **Enable metrics:** Add Prometheus metrics for monitoring
5. **Use multiple workers:** Set `num_workers` based on CPU cores
6. **Remove volumes:** Don't mount code in production, bake it into the image
7. **Enable OPcache:** Already included in our Dockerfile
8. **Use health checks:** Add health check endpoints
9. **Log aggregation:** Use proper logging solutions (ELK stack, etc.)
10. **Resource limits:** Set appropriate memory and CPU limits in Docker

---

## Final Project Structure (Multi-Episode Setup)
```
road-runner-server/
├── episodes/
│   ├── 0 - first contact/
│   │   ├── app/
│   │   │   ├── .rr.yaml
│   │   │   ├── composer.json
│   │   │   ├── worker.php
│   │   │   └── vendor/
│   │   ├── Dockerfile
│   │   └── docker-compose.yml
│   │
│   └── 1 - Symfony integration/
│       ├── app/
│       │   ├── .rr.yaml
│       │   ├── bin/
│       │   │   ├── console
│       │   │   └── roadrunner-worker
│       │   ├── config/
│       │   │   ├── bundles.php
│       │   │   └── packages/
│       │   │       └── baldinof_road_runner.yaml
│       │   ├── src/
│       │   ├── public/
│       │   └── vendor/
│       ├── Dockerfile
│       └── docker-compose.yml
│
└── .git
```

---

## Advanced Configuration Options

### Worker Pool Configuration
```yaml
http:
  pool:
    num_workers: 4              # Number of worker processes
    max_jobs: 1000              # Restart worker after N jobs (0 = never)
    allocate_timeout: 60s       # Timeout for worker allocation
    destroy_timeout: 60s        # Timeout for worker destruction
    supervisor:
      watch_tick: 1s            # How often to check worker health
      ttl: 0s                   # Maximum worker lifetime (0 = unlimited)
      idle_ttl: 10s             # Maximum worker idle time
      exec_ttl: 60s             # Maximum execution time per request
      max_worker_memory: 128    # Maximum memory per worker (MB)
```

### Middleware Configuration
```yaml
http:
  middleware: ["headers", "gzip"]
  
  headers:
    cors:
      allowed_origin: "*"
      allowed_headers: "*"
      allowed_methods: "GET,POST,PUT,DELETE"
      allow_credentials: true
    
    response:
      X-Powered-By: "RoadRunner"
```

### Static Files
```yaml
http:
  static:
    dir: "public"
    forbid: [".htaccess", ".git"]
```

---

## Resources

- **RoadRunner Official Docs:** https://roadrunner.dev
- **Symfony Bundle (Baldinof):** https://github.com/Baldinof/roadrunner-bundle
- **Official Docker Images:** https://hub.docker.com/r/spiralscout/roadrunner
- **RoadRunner GitHub:** https://github.com/roadrunner-server/roadrunner
- **Spiral Framework:** https://spiral.dev (creators of RoadRunner)
- **PHP PSR-7:** https://www.php-fig.org/psr/psr-7/

---

## Contributing

Found an issue or want to improve this guide? Contributions are welcome!

---

## License

This guide is provided as-is for educational purposes.

---

**Happy coding with RoadRunner! 🚀**
