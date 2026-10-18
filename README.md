# Slime for PHP

[![License](https://img.shields.io/badge/License-BSD--3--Clause--Attribution-blue.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.4-8892BF.svg)](https://www.php.net/)
[![Laravel Compatibility](https://img.shields.io/badge/Laravel-10%2B-FF2D20.svg)](https://laravel.com/)

> **Enterprise CQRS, Event Sourcing, and High-Assurance Token Infrastructure for Modern PHP & Laravel Applications.**

---

## What?

A library that enables domain-driven architecture for PHP, bundled with frequently used utilities.

---

## Core Features

### 1. CQRS & Business Process Automation (`SlimeSystems\Hatsume`)
* **Commands & Events:** Strictly typed business intents and state disclosures with integrated schema validation.
* **Process Orchestration:** State-machine and event-driven workflow execution via `ProcessHelper`.
* **Optimistic Concurrency:** Native conflict detection (`ConcurrentWriteDetected`) to protect critical business records from race conditions.
* **Routing & Dispatch:** Flexible event queues and routers supporting both synchronous in-memory handling and distributed queue pipelines.

### 2. High-Performance Persistence (`SlimeSystems\Eloquent`)
* **Event Sourcing Engine:** Turnkey storage, retrieval, and deterministic replaying of domain events.
* **Query Sharding:** Transparent shard resolution and partitioned query execution for worker scalability.
* **Compact Binary Serialization:** High-efficiency MessagePack serialization with native support for ObjectIDs, ISO timestamps, and domain entities.
* **State Projections:** Decoupled read models and state persistence optimized for reporting and analytics queries.

### 3. Identity & Token Infrastructure (`SlimeSystems\Tokens`)
* **High-Assurance API Tokens:** Production-ready token issuance and verification supporting modern cryptographic standards (PASETO v4, JWT).
* **Federated Auth Verification:** Fast, resilient Google Firebase ID token validation with automated public key caching and rotation.
* **State & Continuation Tokens:** Tamper-resistant, encrypted/signed tokens for stateless pagination cursors, multi-step transaction state, and flow continuity.

### 4. Framework Integration (`SlimeSystems\Bridge`)
* **Laravel Bridge:** Native integration into Laravel's event bus, service container, and Eloquent ORM.

---

## Installation

Install via Composer:

```bash
composer require slime-systems/php-slime
```

### System Requirements

* **PHP:** ^8.4 or higher
* **Laravel / Illuminate Components:** ^10.0, ^11.0, or ^12.0
* Recommended extensions: `ext-msgpack` (or bundled userland fallback), `ext-sodium`

---

## License

This software is released under the **BSD 3-Clause Attribution License**. See [LICENSE.md](LICENSE.md) for full license details.
