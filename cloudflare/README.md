# Gaston Power Kenya Ltd — Cloudflare Worker

This is the Cloudflare-native edition of the Gaston Power generator maintenance system. It uses JavaScript on Cloudflare Workers and Cloudflare D1 (SQLite). The original PHP/MySQL project remains in the repository root.

## Features
- First-run administrator setup (only when the users table is empty)
- Sign in/out, password changes, eight-hour server-side sessions
- Administrator, technician and viewer roles
- Customer and generator registers
- Maintenance job tracking and technician assignment
- Spare-parts inventory and low-stock dashboard count
- Audit log records for important account and record actions
- Password hashing with PBKDF2-SHA-256, prepared D1 statements, CSRF checks, login throttling and security response headers

## Deploy using Cloudflare dashboard
1. Push this repository to GitHub (the project files are in the `cloudflare/` folder).
2. In Cloudflare, open **Workers & Pages** and create a Worker connected to this GitHub repository.
3. Set the Worker root directory / build directory to `cloudflare` if the setup screen offers a root-directory option. The entry point is `src/index.js`.
4. Create a D1 database named `gaston-power-db`.
5. Open the Worker settings and add a D1 database binding:
   - Variable name: `DB`
   - Database: `gaston-power-db`
6. Open `cloudflare/wrangler.jsonc` and replace `REPLACE_WITH_YOUR_D1_DATABASE_ID` with the database ID shown by Cloudflare.
7. Apply the migration in `migrations/0001_initial.sql` to the D1 database using the D1 console, or run `npx wrangler d1 migrations apply gaston-power-db --remote` from this folder after authenticating Wrangler.
8. Deploy the Worker.
9. Open the Worker URL. The first visit will show initial administrator setup. Create a strong unique password of at least 12 characters.
10. Sign in and create staff accounts from the Staff tab.

## Wrangler CLI alternative
From this directory:
```bash
npm install --save-dev wrangler
npx wrangler login
npx wrangler d1 create gaston-power-db
# Copy the returned database_id into wrangler.jsonc
npx wrangler d1 migrations apply gaston-power-db --remote
npx wrangler deploy
``

## Important notes
- D1 is SQLite-compatible, not MySQL. Do not point this Worker at the old MySQL database.
- Do not commit passwords, API tokens, session secrets, or real customer information to GitHub.
- The first-run setup endpoint becomes unavailable as soon as the first administrator is created.
- This is an initial implementation and must be tested with sample data before company use. Add backups, operational monitoring, review of access permissions, and a security review before storing real customer or generator records.
- The current UI uses IDs to link generator/customer and job/technician records. A later usability pass can replace these with searchable dropdowns.
