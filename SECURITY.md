# Security

This SDK lets a shop's server create and check Lightning and on-chain payments for a sattle.me
merchant. A flaw here could let an attacker be paid instead of the merchant, mark an order paid
that is not, or learn a key: please report it **privately**, never in a public issue, pull request
or discussion.

## How to report

- On GitHub: **Security** tab of this repository → **Report a vulnerability** (private
  vulnerability reporting).
- Or by email: security@sattle.me, subject starting with `[security] checkout-php`.

Please include what you found, the version of the SDK (`Client::VERSION`), PHP and OpenSSL, and,
when you can, the steps or the input that show it. Do not test against real merchants' funds or
keys.

## What is in scope

- This repository's code: a way to make the SDK accept a wrong invoice, a wrong payment or a forged
  record, leak a key or a secret, or reach another host than the merchant's relays, its Bitcoin
  node and the services the protocol names (or the node otherwise than its URL's rule allows).
- A flaw in the protocol itself (a rule of `SPEC.md` that, followed as written, is unsafe) goes to
  [checkout-spec](https://github.com/sattleme/checkout-spec/security), through the same
  channels.

We will acknowledge your report, keep you informed, and credit you when the fix is published,
unless you prefer otherwise.
