<?php

use Mdanter\Ecc\Crypto\Signature\SchnorrSignature;
use Mdanter\Ecc\Crypto\Signature\SchnorrSigner;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use Tests\Support\FastSchnorr;
use Tests\Support\TestSigner;

// The test processes sign with tests/Support/FastSign.php instead of the
// constant-time vendor signer (an order of magnitude slower). These guard that
// the stand-in still produces signatures the rest of the world accepts.

it('reproduces the official BIP-340 test vector 0', function () {
    $signature = FastSchnorr::sign(
        str_repeat('0', 63).'3',
        str_repeat('0', 64),
        str_repeat("\0", 32),
    );

    expect(strtoupper($signature))->toBe('E907831F80848D1069A5371B402410364BDF1C5F8307B0084C55F1CE2DCA821525F66A4A85EA8B71E482A74F382D2CE5EBEEE8FDB2172F477DF4900D310536C0');
});

it('signs events so that the vendor verifier accepts them, and rejects a changed one', function () {
    $signer = new TestSigner;
    $event = $signer->sign(1, [['t', 'fast']], 'hello');

    $verifier = new SchnorrSigner;

    expect($verifier->verify($signer->pubkey, $event['sig'], $event['id']))->toBeTrue()
        ->and($verifier->verify($signer->pubkey, $event['sig'], hash('sha256', 'another event')))->toBeFalse();
});

it('is the Sign class every `new Sign` in this process gets', function () {
    // Not the vendor file: the app's own signers would otherwise still pay for the slow maths.
    expect((new ReflectionClass(Sign::class))->getFileName())->toEndWith('tests/Support/FastSign.php');
});

it('keeps the id the event already carries and fills pubkey and signature', function () {
    $signer = new TestSigner;
    $event = (new Event)->setKind(1)->setContent('x')->setCreatedAt(1_700_000_000);

    (new Sign)->signEvent($event, $signer->secret);

    expect($event->getPublicKey())->toBe($signer->pubkey)
        ->and($event->getId())->toBe(hash('sha256', Sign::serializeEvent($event)))
        ->and($event->getSignature())->toHaveLength(128);
});

it('is the SchnorrSignature every Event::verify() in this process gets', function () {
    expect((new ReflectionClass(SchnorrSignature::class))->getFileName())->toEndWith('tests/Support/FastSign.php');
});

it('verifies exactly what the vendor verifier verifies: valid, changed, foreign and out-of-range input', function () {
    $signer = new TestSigner;
    $other = new TestSigner;
    $vendor = new SchnorrSigner;
    $fast = new SchnorrSignature;
    $order = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';
    $flip = fn (string $hex, int $at): string => substr($hex, 0, $at).dechex((hexdec($hex[$at]) + 1) % 16).substr($hex, $at + 1);

    $cases = [];

    foreach (range(1, 6) as $i) {
        $event = $signer->sign(1, [['t', "case-{$i}"]], "hello {$i}");
        $cases["valid {$i}"] = [$signer->pubkey, $event['sig'], $event['id'], true];
        $cases["signature changed in r {$i}"] = [$signer->pubkey, $flip($event['sig'], 3 + $i), $event['id'], false];
        $cases["signature changed in s {$i}"] = [$signer->pubkey, $flip($event['sig'], 70 + $i), $event['id'], false];
        $cases["another message {$i}"] = [$signer->pubkey, $event['sig'], hash('sha256', "another {$i}"), false];
        $cases["another key {$i}"] = [$other->pubkey, $event['sig'], $event['id'], false];
    }

    $event = $signer->sign(1, [], 'range');
    $cases['s = n'] = [$signer->pubkey, substr($event['sig'], 0, 64).strtolower($order), $event['id'], false];
    $cases['r = p'] = [$signer->pubkey, str_repeat('f', 64).substr($event['sig'], 64), $event['id'], false];
    $cases['zero signature'] = [$signer->pubkey, str_repeat('0', 128), $event['id'], false];
    $cases['uppercase signature'] = [$signer->pubkey, strtoupper($event['sig']), $event['id'], true];
    $cases['short signature'] = [$signer->pubkey, substr($event['sig'], 2), $event['id'], false];
    $cases['key not on the curve'] = [str_repeat('0', 63).'5', $event['sig'], $event['id'], null];

    foreach ($cases as $name => [$key, $signature, $message, $expected]) {
        $reference = null;
        try {
            $reference = $vendor->verify($key, $signature, $message);
        } catch (Throwable) {
            // The vendor throws on a key it cannot lift; the stand-in asks it then, so it throws as well.
        }

        $answer = null;
        try {
            $answer = $fast->verify($key, $signature, $message);
        } catch (Throwable) {
        }

        expect($answer)->toBe($reference, $name);

        if ($expected !== null) {
            expect($answer)->toBe($expected, $name);
        }
    }
});
