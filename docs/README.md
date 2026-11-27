# Midway Weather documentation

Start with the [project README](../README.md) for features, screenshots, and a local demo.

## Current guides

- [Updating an existing installation](installation/upgrading.md): state-preserving Git/ZIP upgrades, backups, verification and recovery.
- [Battery troubleshooting](installation/battery-troubleshooting.md): Ambient field meanings and a restricted diagnostic for missing readings.

- [Windows installation](installation/windows.md): first-time setup, demo, Ambient station connection, daily startup and troubleshooting.
- [Requirements and compatibility](installation/requirements.md): host/display roles, prerequisites and validation limits.

- [Setup and configuration](configuration.md): runtime, providers, SQLite, admin credentials, maps, settings precedence.
- [Administration](admin.md): layouts/previews, quips, Atmosphere 2.0, seasonal themes, map framing, sources, diagnostics.
- [Environmental sensors](environmental-sensors.md): shipped sensor tiles, the privately validated Govee pool integration, and future provider directions.
- [Providers and sensor tiles](providers.md): current connection, discovery, publication, freshness, and retention behavior.
- [Architecture](architecture.md): collection lifecycle, storage, routes, extension points.
- [Operations](operations.md): scheduling, retention, backups, troubleshooting, developer validation.
- [PWA](pwa.md): install identity, cache boundaries, network requirements.
- [Screenshot tour](screenshots/README.md): Atmosphere 2.0 demo scenes and historical hosted dashboard and admin captures.
- [Canonical Weather API](contracts/weather-v2.md): consumer contract mapping and validation.

## Additional references

- [Roadmap](roadmap.md): implemented features and future ideas.
- [Legacy interface references](ui-reference/README.md): privacy-edited external UI references, separate from current application screenshots.
- [Ambient API reference](ambientweather.apib) and [device data notes](DeviceDataSpecs.md): provider reference material; check current provider documentation before integrations.

This public snapshot contains application source and self-hosting guidance. Private deployment configuration and operational audit records are maintained separately.
