# BIP-340 test vectors

`test-vectors.csv` is the official test vector file of BIP-340 (Schnorr signatures for secp256k1),
copied unchanged from the `bitcoin/bips` repository:

- Source: https://github.com/bitcoin/bips/blob/927b6de9915c9262615a6399de51b200f81e5aa4/bip-0340/test-vectors.csv
- Commit: `927b6de9915c9262615a6399de51b200f81e5aa4` (the default branch on 2026-10-02)
- SHA-256: `34c9d1d9c3a88d524bc80778540dc43f8306ec249a7485293063c376db851c2d`
- License: BIP-340 publishes its test vectors "under the BSD-2-Clause License, or the MIT License,
  or CC0 1.0, at your choice"; this repository takes them under the MIT License.

They are test data only: never part of the package (`.gitattributes`), and never copied into
`spec/`, which holds the pinned copy of checkout-spec alone. `tests/Crypto/SchnorrTest.php` checks
the file's SHA-256 before reading it.
