<?php

/*
 * What phpunit.xml pins so a prod-like .env cannot change what the suite tests.
 * Each switch here once turned the stream daemon tests red at every hour of the day.
 */

test('the suite runs with the radio commentary off, whatever the .env says', function () {
    // TV_COMMENTARY=true in .env put the SceneDirector into every stream daemon run: a slot with nothing
    // to say holds `silent_min_scene_seconds` (15 s), the promo loop outlasted --stop-after and no teaser came.
    expect(config('tv.commentary.enabled'))->toBeFalse();
});
