"""Cases of BIP-340 and ECDH computed by libsecp256k1 (through coincurve), for the SDK's own
implementation on OpenSSL to be checked against (tests/Differential). CI only.

Usage: python3 -I generate.py <count> <seed> <output.json>

The cases come from a seeded generator, so that a failure can be reproduced: they are test data,
never keys of anyone.
"""

import json
import random
import sys

from coincurve import PrivateKey, PublicKey, PublicKeyXOnly

ORDER = 0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141
PRIME = 0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F


def secret(rng):
    while True:
        value = rng.getrandbits(256)
        if 0 < value < ORDER:
            return value.to_bytes(32, "big")


def x_only(raw):
    try:
        PublicKeyXOnly(raw)
        return True
    except ValueError:
        return False


def verify(public, message, signature):
    try:
        return PublicKeyXOnly(public).verify(signature, message)
    except ValueError:
        return False


def main():
    count, seed, output = int(sys.argv[1]), int(sys.argv[2]), sys.argv[3]
    rng = random.Random(seed)
    edges = [(1).to_bytes(32, "big"), (ORDER - 1).to_bytes(32, "big"), (2).to_bytes(32, "big"), ((ORDER - 1) // 2).to_bytes(32, "big")]
    cases = {"signatures": [], "verifications": [], "ecdh": [], "xonly": []}
    for index in range(count):
        key = edges[index] if index < len(edges) else secret(rng)
        message = rng.getrandbits(256).to_bytes(32, "big")
        auxiliary = rng.getrandbits(256).to_bytes(32, "big")
        private = PrivateKey(key)
        public = PublicKeyXOnly.from_secret(key).format()
        signature = private.sign_schnorr(message, auxiliary)
        cases["signatures"].append({"secret": key.hex(), "message": message.hex(), "auxiliary": auxiliary.hex(), "public": public.hex(), "signature": signature.hex()})

        # A valid signature, one bit of it changed, another message, a random one.
        tampered = bytearray(signature)
        tampered[rng.randrange(64)] ^= 1 << rng.randrange(8)
        other = rng.getrandbits(256).to_bytes(32, "big")
        noise = bytes(rng.getrandbits(8) for _ in range(64))
        for sig, msg in ((signature, message), (bytes(tampered), message), (signature, other), (noise, message)):
            cases["verifications"].append({"public": public.hex(), "message": msg.hex(), "signature": sig.hex(), "valid": verify(public, msg, sig)})

        # ECDH: the x coordinate of a·B (NIP-44's shared x), from a full public key of either parity.
        peer = secret(rng)
        point = PublicKey.from_secret(peer).format(compressed=True)
        shared = PublicKey(point).multiply(key).format(compressed=True)[1:]
        cases["ecdh"].append({"secret": key.hex(), "public": point[1:].hex(), "shared": shared.hex()})

        # x-only public keys: random 32 bytes, half of them points; and values at and past p.
        raw = rng.getrandbits(256).to_bytes(32, "big")
        cases["xonly"].append({"x": raw.hex(), "valid": x_only(raw)})
    for value in (0, 1, PRIME - 1, PRIME, PRIME + 1, 2**256 - 1):
        raw = value.to_bytes(32, "big")
        cases["xonly"].append({"x": raw.hex(), "valid": x_only(raw)})
    with open(output, "w", encoding="utf-8") as handle:
        json.dump({"seed": seed, "cases": cases}, handle)


if __name__ == "__main__":
    main()
