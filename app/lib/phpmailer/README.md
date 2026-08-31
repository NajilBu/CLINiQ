# Bundled PHPMailer runtime

This directory contains the three PHPMailer source files required by CLINiQ's SMTP mail helper.

- Version: 7.1.1
- Upstream: https://github.com/PHPMailer/PHPMailer
- License: GNU Lesser General Public License 2.1

The runtime is stored under `app/lib` so it remains part of copied or cloned CLINiQ installations. SMTP usernames and passwords are not stored here; configure them through CLINiQ's Email settings or a local `.env` file.
