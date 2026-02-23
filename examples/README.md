# MikroAPI Examples

This directory contains example applications demonstrating various features of MikroAPI.

## Basic Example

A simple "Hello World" API demonstrating basic routing.

```bash
cd examples/basic
php -S localhost:8000 index.php
```

Test it:
```bash
curl http://localhost:8000/api/hello
curl http://localhost:8000/api/hello/John
```

## Authentication Example

Demonstrates JWT authentication with guards.

```bash
cd examples/auth
php -S localhost:8000 index.php
```

Test it:
```bash
# Login
curl -X POST http://localhost:8000/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'

# Use the token from response
curl http://localhost:8000/auth/me \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```
