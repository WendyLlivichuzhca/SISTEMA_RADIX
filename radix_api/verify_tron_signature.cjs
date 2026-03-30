const tronModule = require('./vendor/TronWeb.node.js');

const Trx =
    tronModule.Trx ||
    tronModule.default?.Trx ||
    tronModule.TronWeb?.Trx ||
    tronModule.default?.TronWeb?.Trx;

function output(payload) {
    process.stdout.write(JSON.stringify(payload));
}

try {
    if (!Trx || typeof Trx.verifyMessageV2 !== 'function') {
        throw new Error('TronWeb verifier not available');
    }

    const encodedPayload = process.argv[2] || '';
    if (!encodedPayload) {
        throw new Error('Missing payload');
    }

    const payload = JSON.parse(Buffer.from(encodedPayload, 'base64').toString('utf8'));
    const message = String(payload.message || '');
    const signature = String(payload.signature || '');
    const wallet = String(payload.wallet || '');

    if (!message || !signature || !wallet) {
        throw new Error('Incomplete payload');
    }

    const recoveredWallet = Trx.verifyMessageV2(message, signature);

    output({
        success: true,
        wallet,
        recovered_wallet: recoveredWallet,
        matches: recoveredWallet === wallet,
    });
} catch (error) {
    output({
        success: false,
        error: error?.message || 'Unknown verification error',
    });
    process.exitCode = 1;
}
