# HP Invoices (hp-pdf-invoices)

Tailored invoices for Holistic People. Supports PDF, Word (DOCX), and Excel (XLSX) export formats.

## Branch & deployment structure

- `dev` — integration branch; every push deploys automatically to Kinsta staging (`deploy-staging.yml`).
- `main` — production branch; advanced only via `dev` → `main` PRs as part of the production deployment process.
- Production deploys run via manual `workflow_dispatch` of `deploy-production.yml`, which always checks out `main`.

## Current Staging Build

**3.0.5**

## Changelog

### 3.0.5
- Create `main` branch and conform to the standard HP plugin deployment structure: production deploy workflow now always checks out `main` instead of the dispatched ref.

### 3.0.4 and earlier
- See git history (changelog started at 3.0.5).
