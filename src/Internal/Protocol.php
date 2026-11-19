<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Internal;

/**
 * Every constant of the protocol (SPEC.md §28), in one place.
 *
 * @internal
 */
final class Protocol
{
    /** The version of checkout-spec this SDK implements (spec/PIN.json). */
    public const SPEC_VERSION = '1.0.0';

    /** The protocol's version, the one its records and keys carry. */
    public const PROTOCOL = 1;

    public const KEY_PREFIX = 'sattleme_ck_';
    public const KEY_BYTES = 32;

    public const RECORD_KIND = 30078;
    public const AUTH_KIND = 22242;
    public const RECORD_PADDING = 256;

    public const CHANNEL_TYPE = 'team-channel';
    public const CHANNEL_VERSION = 1;
    public const DECLARATION_TYPE = 'registry-declaration';
    public const DECLARATION_VERSION = 1;
    public const SATS_CURRENCY = 'SAT';

    public const RELAY_MOVES = 3;
    public const MAX_RELAYS = 10;
    public const MAX_RELAY_URL_LENGTH = 120;
    public const MAX_HOST_LENGTH = 253;
    public const MAX_BUSINESS_NAME = 200;
    public const MAX_REF = 64;
    public const MIN_DURATION = 60;
    public const MAX_DURATION = 3600;
    public const DEFAULT_DURATION = 900;
    public const MAX_NOTE = 100;
    public const GRACE_SECONDS = 120;
    public const CLOCK_TOLERANCE = 300;
    public const RATE_AGE = 3600;

    /** @var list<string> */
    public const DEFAULT_RATE_SOURCES = ['coingecko', 'mempool', 'coinbase', 'kraken', 'opennode'];

    public const MAX_PRICE_TEXT = 64;
    public const MAX_PRICE_EXPONENT = 64;
    public const MAX_EVENTS_PER_QUERY = 1000;
    public const MAX_PAGES = 20;
    /** The `limit` of each page of a complete read (SPEC.md §13): the leases of deposit addresses. */
    public const COMPLETE_READ_LIMIT = 100;
    public const MAX_MESSAGE_BYTES = 1048576;
    public const MAX_BODY_BYTES = 1048576;

    /** An HTTP answer's head (its status lines, its headers, a chunked body's size lines), at most (SPEC.md §15). */
    public const MAX_HEAD_BYTES = 10485760;
    public const MAX_PARALLEL_PAYMENT_CHECKS = 4;

    public const DEFAULT_TIMEOUT_MS = 10000;
    public const MIN_TIMEOUT_MS = 100;
    public const MAX_TIMEOUT_MS = 60000;
    public const DEFAULT_INTERVAL_MS = 2000;
    public const MIN_INTERVAL_MS = 250;
    public const MAX_INTERVAL_MS = 60000;

    public const MAX_EXPIRY_FIELD_WORDS = 7;
    public const DEFAULT_BOLT11_EXPIRY = 3600;
    /** 21 000 000 BTC in millisatoshis: fits a signed 64-bit integer. */
    public const MAX_MSAT = 2100000000000000000;
    /** 2^53 − 1: the largest integer every JSON reader holds exactly. */
    public const MAX_SAFE_INTEGER = 9007199254740991;

    public const CHECKOUT_SALT = 'sattle.me/checkout';
    public const CHECKOUT_TYPE = 'checkout';
    public const CHECKOUT_MARKER = 'c';
    public const CHECKOUT_VERSION_LIGHTNING = 1;
    public const CHECKOUT_VERSION_ONCHAIN = 2;
    public const CHECKOUT_LIFE = 604800;
    public const CHECKOUT_PATH = '/pay';
    public const MAX_REDIRECT_URL = 2048;

    /** Every HTTP request's (SPEC.md §15): the same in every implementation and version. */
    public const USER_AGENT = 'sattleme-Checkout/1';

    public const ONCHAIN_FLOOR = 1000;
    public const MAX_SATS = 2100000000000000;
    public const LOT_TYPE = 'integration-onchain-lot';
    public const LOT_VERSION = 1;
    public const MAX_LOT = 100;
    public const LEASE_TYPE = 'registry-onchain';
    public const LEASE_VERSION = 1;
    public const LEASE_LOOKBACK = 7200;
    public const MAX_ADDRESS_TRIES = 3;
    public const WITNESS_TYPE = 'integration-onchain-seen';
    public const WITNESS_VERSION = 1;
    public const MAX_WITNESS_INPUTS = 100;
    public const CONFIRMATIONS = 3;
    public const REST_TYPE = 'checkout-rest';
    public const REST_VERSION = 1;
    public const MIN_REST_SECONDS = 60;
    public const MAX_TOLERANCE = 1000;
    public const ACCEPTANCE_TYPE = 'checkout-accepted';
    public const ACCEPTANCE_VERSION = 1;
    /**
     * The merchant's acceptance (SPEC.md §31): a record of the team, never the integration's (it
     * opens one but cannot make one), sealed with the channel's key, at an address the integration
     * key derives (Derive::merchantAcceptanceAddress()).
     */
    public const MERCHANT_ACCEPTANCE_TYPE = 'integration-onchain-accepted';
    public const MERCHANT_ACCEPTANCE_VERSION = 1;

    /**
     * mempool.space (Esplora REST): the explorer, unless the channel names the merchant's Bitcoin
     * node (Onchain\BitcoinNode), and the node's fallback when it says `"mempool"`.
     */
    public const MEMPOOL = 'https://mempool.space/api';
    /** mempool.space's WebSocket, which waitForPayment watches an address on without a node. */
    public const MEMPOOL_WEBSOCKET = 'wss://mempool.space/api/v1/ws';
    /** A node URL (SPEC.md §31, "A node URL"): 200 characters at most. */
    public const MAX_NODE_URL = 200;
    /**
     * A node that failed a read less than this long ago, in the same call, is asked after
     * mempool.space (`fallback` `"mempool"`): a node that does not answer does not make each read
     * wait for its timeout.
     */
    public const NODE_FAILED_RECENTLY_SECONDS = 60;
    public const BLOCKCYPHER = 'https://api.blockcypher.com/v1/btc/main';
    /** The explorer's WebSocket: the on-chain part is checked at least this often. */
    public const ONCHAIN_CHECK_INTERVAL_MS = 60000;
    public const RECONNECT_FIRST_MS = 1000;
    public const RECONNECT_MAX_MS = 30000;

    public const INTEGRATION_SALT = 'sattle.me/integration';
    public const SALE_SALT = 'sattle.me/sale';
    public const DECLARATION_SALT = 'sattle.me/registry-declaration';

    private function __construct() {}
}
