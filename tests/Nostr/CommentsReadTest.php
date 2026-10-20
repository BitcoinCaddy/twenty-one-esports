<?php

/*
 * Reading comments, likes and RSVPs in the browser (P48,
 * resources/js/commentsRead.js) runs where it lives:
 * tests/js/commentsRead.test.mjs under Node. It fails if any Node test fails
 * or is skipped.
 */
test('comments count only in the target scope, pages merge without duplicates, likes and RSVPs count the newest per author, moderation only from the creator', function () {
    nodeTest('tests/js/commentsRead.test.mjs', 8);
});
