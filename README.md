# splicewire/laravel-beam-synthetic

Synthesize mode for Beam hosts. It will let a host fill itself with synthetic tenants built from
blueprints, read a portfolio of them in memory, and project its own numbers forward, using the same
pricing and revenue code as its real tenants.

This release is the package scaffold: the service provider and the `beam.synthetic` config root.
The curve kernel, the blueprint family, the materializer and the projection engine follow.

## Install

Require `splicewire/laravel-beam-synthetic`. It requires `splicewire/laravel-beam`,
`splicewire/laravel-beam-tenancy` and `splicewire/laravel-beam-commerce`. The provider is
auto-discovered. To override config, publish `config/beam/synthetic.php`:

```bash
php artisan vendor:publish --tag=beam-synthetic-config
```

## Registries

This package declares no registries yet, and registers into none.

## Testing

Run `composer test`.
