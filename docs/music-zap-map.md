# Wavlake music zap map (for the live stream slides)

Source of truth is `resources/data/music/wavlake-index.json`; this file is its
human-readable twin. Zap routing, found over the artists' Nostr profiles
(kind-0 verified): **where the profile carries a lightning address (lud16),
the QR encodes that address** - every Nostr wallet zaps the artist directly.
With only an npub, the QR points at the njump profile page (zap button there).
Artists without any Nostr presence keep their Wavlake track page as the target
(zaps route through Wavlake's keysend node, customKey 16180339).

## Artists with a Nostr zap address

| Artist | npub | zap (QR target) | tracks |
|---|---|---|---|
| Ainsley Costello | `npub13qrrw2h4z52m7jh0spefrwtysl4psfkfv6j4j672se5hkhvtyw7qu0almy` | `ainsleycostello@getalby.com` | 12 |
| Contra | `npub14hq5lgadtyy9dhvtszq46dnl0s0xwdddqr7e32rdqqhma8a4xhsspxjjzu` | `contra@rizful.com` | 1 |
| Fundamentals | `npub12eml5kmtrjmdt0h8shgg32gye5yqsf2jha6a70jrqt82q9d960sspky99g` | `fundamentals@zeuspay.com` | 1 |
| Joe Martin | `npub19r9qrxmckj2vyk5a5ttyt966s5qu06vmzyczuh97wj8wtyluktxqymeukr` | `joe@nostrich.org` | 62 |
| Patrick Manian | `npub1kzj9udp7pxuwyvx958hhxvrdx8xdhn4fvy3yv78hjxvka42lye5sz6nqyu` | `https://njump.me/npub1kzj9udp7pxuwyvx958hhxvrdx8xdhn4fvy3yv78hjxvka42lye5sz6nqyu` | 2 |
| Riot in Progress | `npub1ljr66vfadhr5rkldt2yhyzn7yqqqkeedhg9fq8vkyrdycgpzgtws440z3v` | `guysmilely@fountain.fm` | 2 |
| shy kids | `npub1tw0t53nnge3m5wmzhzqpudr7whe7qpvyyj9q2dqnsf8ef97xgsrqugjc3r` | `https://njump.me/npub1tw0t53nnge3m5wmzhzqpudr7whe7qpvyyj9q2dqnsf8ef97xgsrqugjc3r` | 1 |

## Tracks with a Nostr zap address

| File (under `storage/app/stream/music/`) | Artist | Title | QR target |
|---|---|---|---|
| `wavlake/Can't Say I'm Not Trying - Ainsley Costello__v1.m4a` | Ainsley Costello | Can't Say I'm Not Trying | `ainsleycostello@getalby.com` |
| `wavlake/Change Your Mind - Ainsley Costello__v1.m4a` | Ainsley Costello | Change Your Mind | `ainsleycostello@getalby.com` |
| `wavlake/Cherry On Top - Ainsley Costello__v1.m4a` | Ainsley Costello | Cherry On Top | `ainsleycostello@getalby.com` |
| `wavlake/Cherry On Top (90's Revival Remix) with Stereon - Ainsley Costello__v1.m4a` | Ainsley Costello | Cherry On Top (90's Revival Remix) with Stereon | `ainsleycostello@getalby.com` |
| `wavlake/Daydreamer - Ainsley Costello__v1.m4a` | Ainsley Costello | Daydreamer | `ainsleycostello@getalby.com` |
| `wavlake/EXLESS - Ainsley Costello__v1.m4a` | Ainsley Costello | EXLESS | `ainsleycostello@getalby.com` |
| `wavlake/People Pleaser - Ainsley Costello__v1.m4a` | Ainsley Costello | People Pleaser | `ainsleycostello@getalby.com` |
| `wavlake/Rescued - Ainsley Costello__v1.m4a` | Ainsley Costello | Rescued | `ainsleycostello@getalby.com` |
| `wavlake/THEREGOESMY! - Ainsley Costello__v1.m4a` | Ainsley Costello | THEREGOESMY! | `ainsleycostello@getalby.com` |
| `wavlake/Two Ships - Ainsley Costello__v1.m4a` | Ainsley Costello | Two Ships | `ainsleycostello@getalby.com` |
| `wavlake/Two Ships JSTR REMIX - Ainsley Costello__v1.m4a` | Ainsley Costello | Two Ships JSTR REMIX | `ainsleycostello@getalby.com` |
| `wavlake/You Problem - Ainsley Costello__v1.m4a` | Ainsley Costello | You Problem | `ainsleycostello@getalby.com` |
| `wavlake/Debasement - Contra__v1.m4a` | Contra | Debasement | `contra@rizful.com` |
| `wavlake/Speedo Vigilante - Fundamentals__v1.m4a` | Fundamentals | Speedo Vigilante | `fundamentals@zeuspay.com` |
| `Alone In Valentine - Joe Martin__v1.m4a` | Joe Martin | Alone In Valentine | `joe@nostrich.org` |
| `Alone In Valentine - single - Joe Martin__v1.m4a` | Joe Martin | Alone In Valentine - single | `joe@nostrich.org` |
| `Amelia - Joe Martin__v1.m4a` | Joe Martin | Amelia | `joe@nostrich.org` |
| `Amelia (set 2) - Joe Martin__v1.m4a` | Joe Martin | Amelia (set 2) | `joe@nostrich.org` |
| `Another Sad Song - Joe Martin__v1.m4a` | Joe Martin | Another Sad Song | `joe@nostrich.org` |
| `Backseat Driver - Joe Martin__v1.m4a` | Joe Martin | Backseat Driver | `joe@nostrich.org` |
| `Backseat Driver - single - Joe Martin__v1.m4a` | Joe Martin | Backseat Driver - single | `joe@nostrich.org` |
| `Born To Die Young - Joe Martin__v1.m4a` | Joe Martin | Born To Die Young | `joe@nostrich.org` |
| `Brown Paper Bag - Joe Martin__v1.m4a` | Joe Martin | Brown Paper Bag | `joe@nostrich.org` |
| `Can't Promise You The World - Joe Martin__v1.m4a` | Joe Martin | Can't Promise You The World | `joe@nostrich.org` |
| `Checkmate - Joe Martin__v1.m4a` | Joe Martin | Checkmate | `joe@nostrich.org` |
| `Coal Town - Joe Martin__v1.m4a` | Joe Martin | Coal Town | `joe@nostrich.org` |
| `Crocodile Tears - Joe Martin__v1.m4a` | Joe Martin | Crocodile Tears | `joe@nostrich.org` |
| `Daddy Gene - Joe Martin__v1.m4a` | Joe Martin | Daddy Gene | `joe@nostrich.org` |
| `Daddy Gene (set 1 ) - Joe Martin__v1.m4a` | Joe Martin | Daddy Gene (set 1 ) | `joe@nostrich.org` |
| `Denver - Joe Martin__v1.m4a` | Joe Martin | Denver | `joe@nostrich.org` |
| `Denver (set 1) - Joe Martin__v1.m4a` | Joe Martin | Denver (set 1) | `joe@nostrich.org` |
| `Doesn't Rain In LA - Joe Martin__v1.m4a` | Joe Martin | Doesn't Rain In LA | `joe@nostrich.org` |
| `Doesn't Rain In LA (set 1) - Joe Martin__v1.m4a` | Joe Martin | Doesn't Rain In LA (set 1) | `joe@nostrich.org` |
| `Empty Passenger Seat - Joe Martin__v1.m4a` | Joe Martin | Empty Passenger Seat | `joe@nostrich.org` |
| `wavlake/Empty Passenger Seat - Joe Martin__v1.m4a` | Joe Martin | Empty Passenger Seat | `joe@nostrich.org` |
| `Empty Passenger Seat (set 2) - Joe Martin__v1.m4a` | Joe Martin | Empty Passenger Seat (set 2) | `joe@nostrich.org` |
| `Fell For A Promise (set 2) - Joe Martin__v1.m4a` | Joe Martin | Fell For A Promise (set 2) | `joe@nostrich.org` |
| `Fell For A Promise feat. Harriet - Joe Martin__v1.m4a` | Joe Martin | Fell For A Promise feat. Harriet | `joe@nostrich.org` |
| `Forgotten Country Song - Joe Martin__v1.m4a` | Joe Martin | Forgotten Country Song | `joe@nostrich.org` |
| `Forgotten Country Song (set 2) - Joe Martin__v1.m4a` | Joe Martin | Forgotten Country Song (set 2) | `joe@nostrich.org` |
| `Full Time Dreamer - Joe Martin__v1.m4a` | Joe Martin | Full Time Dreamer | `joe@nostrich.org` |
| `Full Time Dreamer (set 2) - Joe Martin__v1.m4a` | Joe Martin | Full Time Dreamer (set 2) | `joe@nostrich.org` |
| `Hand Me Down Heart - Joe Martin__v1.m4a` | Joe Martin | Hand Me Down Heart | `joe@nostrich.org` |
| `Heartbreak Cult - Joe Martin__v1.m4a` | Joe Martin | Heartbreak Cult | `joe@nostrich.org` |
| `Heartbreak Cult (set 1) - Joe Martin__v1.m4a` | Joe Martin | Heartbreak Cult (set 1) | `joe@nostrich.org` |
| `Hero - Joe Martin__v1.m4a` | Joe Martin | Hero | `joe@nostrich.org` |
| `High Gravity - Joe Martin__v1.m4a` | Joe Martin | High Gravity | `joe@nostrich.org` |
| `High Gravity (set 2) - Joe Martin__v1.m4a` | Joe Martin | High Gravity (set 2) | `joe@nostrich.org` |
| `Kaila Don't Go - Joe Martin__v1.m4a` | Joe Martin | Kaila Don't Go | `joe@nostrich.org` |
| `Kaila Don't Go (set 2) - Joe Martin__v1.m4a` | Joe Martin | Kaila Don't Go (set 2) | `joe@nostrich.org` |
| `Killing Me Slowly - Joe Martin__v1.m4a` | Joe Martin | Killing Me Slowly | `joe@nostrich.org` |
| `Killing Me Slowly (set 1) - Joe Martin__v1.m4a` | Joe Martin | Killing Me Slowly (set 1) | `joe@nostrich.org` |
| `Letters Of Regret - Joe Martin__v1.m4a` | Joe Martin | Letters Of Regret | `joe@nostrich.org` |
| `Letters Of Regret (set 1) - Joe Martin__v1.m4a` | Joe Martin | Letters Of Regret (set 1) | `joe@nostrich.org` |
| `Love Strong - Joe Martin__v1.m4a` | Joe Martin | Love Strong | `joe@nostrich.org` |
| `Love Strong (set 1) - Joe Martin__v1.m4a` | Joe Martin | Love Strong (set 1) | `joe@nostrich.org` |
| `Man You Need - Joe Martin__v1.m4a` | Joe Martin | Man You Need | `joe@nostrich.org` |
| `Money For The Needy - Joe Martin__v1.m4a` | Joe Martin | Money For The Needy | `joe@nostrich.org` |
| `Money For The Needy (set 2) - Joe Martin__v1.m4a` | Joe Martin | Money For The Needy (set 2) | `joe@nostrich.org` |
| `More Than Just Your Loving - Joe Martin__v1.m4a` | Joe Martin | More Than Just Your Loving | `joe@nostrich.org` |
| `More Than Just Your Loving (set 2) - Joe Martin__v1.m4a` | Joe Martin | More Than Just Your Loving (set 2) | `joe@nostrich.org` |
| `Silent Vow - Joe Martin__v1.m4a` | Joe Martin | Silent Vow | `joe@nostrich.org` |
| `Silent Vow (set 1) - Joe Martin__v1.m4a` | Joe Martin | Silent Vow (set 1) | `joe@nostrich.org` |
| `Silhouettes and Sunsets - Joe Martin__v1.m4a` | Joe Martin | Silhouettes and Sunsets | `joe@nostrich.org` |
| `Smokin' and Cryin' feat. Katy Hurt - Joe Martin__v1.m4a` | Joe Martin | Smokin' and Cryin' feat. Katy Hurt | `joe@nostrich.org` |
| `Strangers To Lovers - Joe Martin__v1.m4a` | Joe Martin | Strangers To Lovers | `joe@nostrich.org` |
| `Strangers To Lovers - single - Joe Martin__v1.m4a` | Joe Martin | Strangers To Lovers - single | `joe@nostrich.org` |
| `Take Me Home Tonight - Joe Martin__v1.m4a` | Joe Martin | Take Me Home Tonight | `joe@nostrich.org` |
| `Take Me Home Tonight (set 2) - Joe Martin__v1.m4a` | Joe Martin | Take Me Home Tonight (set 2) | `joe@nostrich.org` |
| `The Jetsons - Joe Martin__v1.m4a` | Joe Martin | The Jetsons | `joe@nostrich.org` |
| `Want You To Stay - Joe Martin__v1.m4a` | Joe Martin | Want You To Stay | `joe@nostrich.org` |
| `Way I Need You - Joe Martin__v1.m4a` | Joe Martin | Way I Need You | `joe@nostrich.org` |
| `When The Time Is Right - Joe Martin__v1.m4a` | Joe Martin | When The Time Is Right | `joe@nostrich.org` |
| `When The Time Is Right (set 1) - Joe Martin__v1.m4a` | Joe Martin | When The Time Is Right (set 1) | `joe@nostrich.org` |
| `Who You Really Are - Joe Martin__v1.m4a` | Joe Martin | Who You Really Are | `joe@nostrich.org` |
| `Who You Really Are (set 1) - Joe Martin__v1.m4a` | Joe Martin | Who You Really Are (set 1) | `joe@nostrich.org` |
| `wavlake/Never Attained Anything - Patrick Manian__v1.m4a` | Patrick Manian | Never Attained Anything | `https://njump.me/npub1kzj9udp7pxuwyvx958hhxvrdx8xdhn4fvy3yv78hjxvka42lye5sz6nqyu` |
| `wavlake/Rightly So - Patrick Manian__v1.m4a` | Patrick Manian | Rightly So | `https://njump.me/npub1kzj9udp7pxuwyvx958hhxvrdx8xdhn4fvy3yv78hjxvka42lye5sz6nqyu` |
| `wavlake/Marrow Road - Riot in Progress__v1.m4a` | Riot in Progress | Marrow Road | `guysmilely@fountain.fm` |
| `wavlake/Nevertheless - Riot in Progress__v1.m4a` | Riot in Progress | Nevertheless | `guysmilely@fountain.fm` |
| `wavlake/bye bye birdie - shy kids__v1.m4a` | shy kids | bye bye birdie | `https://njump.me/npub1tw0t53nnge3m5wmzhzqpudr7whe7qpvyyj9q2dqnsf8ef97xgsrqugjc3r` |

## Tracks without a Nostr profile (Wavlake page as target)

| File (under `storage/app/stream/music/`) | Artist | Title | QR target |
|---|---|---|---|
| `wavlake/Wealthy Man - Arthur Yoria__v1.m4a` | Arthur Yoria | Wealthy Man | `tbd (needs track id)` |
| `wavlake/Good Times ft Quido - Black Vulcanite__v1.m4a` | Black Vulcanite | Good Times ft Quido | `tbd (needs track id)` |
| `wavlake/Keep On Movin' - Cal Volpe__v1.m4a` | Cal Volpe | Keep On Movin' | `tbd (needs track id)` |
| `wavlake/Renaissance Man - Cal Volpe__v1.m4a` | Cal Volpe | Renaissance Man | `tbd (needs track id)` |
| `wavlake/MEMOBOT - Cristo Currensi__v1.m4a` | Cristo Currensi | MEMOBOT | `tbd (needs track id)` |
| `wavlake/Patience - Greensands__v1.m4a` | Greensands | Patience | `tbd (needs track id)` |
| `wavlake/Garbage Ex-Employed - ikki_imittio__v1.m4a` | ikki_imittio | Garbage Ex-Employed | `tbd (needs track id)` |
| `wavlake/Bullet Train - My Friend Jimi__v1.m4a` | My Friend Jimi | Bullet Train | `tbd (needs track id)` |
| `wavlake/Fix the Money - NumberGoExp__v1.m4a` | NumberGoExp | Fix the Money | `tbd (needs track id)` |
| `wavlake/Mask Off - NumberGoExp__v1.m4a` | NumberGoExp | Mask Off | `tbd (needs track id)` |
| `wavlake/Piano keys, watch out for these - sabu__v1.m4a` | sabu | Piano keys, watch out for these | `tbd (needs track id)` |
| `wavlake/Chasing Dreams - Social Hijack__v1.m4a` | Social Hijack | Chasing Dreams | `tbd (needs track id)` |
| `wavlake/The Rhythm of Your Breath - Sorout Beats__v1.m4a` | Sorout Beats | The Rhythm of Your Breath | `tbd (needs track id)` |
| `A Little More Here - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | A Little More Here | `https://wavlake.com/track/f9e45308-6df2-4e03-8cbd-8dd3a9b6f38c` |
| `Almost Elsewhere - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Almost Elsewhere | `tbd (needs track id)` |
| `Interoception - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Interoception | `https://wavlake.com/track/0eef8584-2d47-46d6-904c-7e50dcd788f2` |
| `Leave The Porch Light Low - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Leave The Porch Light Low | `https://wavlake.com/track/27a9ff24-12d5-4931-b81c-e907ef601790` |
| `wavlake/Leave The Porch Light Low - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Leave The Porch Light Low | `https://wavlake.com/track/27a9ff24-12d5-4931-b81c-e907ef601790` |
| `Living Death - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Living Death | `https://wavlake.com/track/28b6e197-87df-4886-86b5-804dddf847b6` |
| `MiddleMan - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | MiddleMan | `https://wavlake.com/track/0f41655e-ad31-4ca7-9b70-7a7c699719e5` |
| `Single Note - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Single Note | `tbd (needs track id)` |
| `Six Good Weeks Instrumental - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Six Good Weeks Instrumental | `tbd (needs track id)` |
| `Sleeper Current - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Sleeper Current | `tbd (needs track id)` |
| `Sticky Carpet Cosmos - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Sticky Carpet Cosmos | `https://wavlake.com/track/9c780643-378e-4a85-9d89-5c97d99d71c6` |
| `Tguitar - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Tguitar | `https://wavlake.com/track/c5ea0863-f82f-4644-9a48-8c682751db02` |
| `The Chair Beside Me - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | The Chair Beside Me | `tbd (needs track id)` |
| `Three In Motion - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Three In Motion | `tbd (needs track id)` |
| `Warm Machine - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Warm Machine | `tbd (needs track id)` |
| `Watermark - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Watermark | `tbd (needs track id)` |
| `When I Die - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | When I Die | `https://wavlake.com/track/7323732c-ae61-4ed4-95e3-502f7b665f5b` |
| `Who Comes Home - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Who Comes Home | `tbd (needs track id)` |
| `Who Comes Home Instrumental - Under The Mulga Groove__v1.m4a` | Under The Mulga Groove | Who Comes Home Instrumental | `tbd (needs track id)` |
| `wavlake/Pirate Radio - Vertigo Kidd__v1.m4a` | Vertigo Kidd | Pirate Radio | `tbd (needs track id)` |
| `wavlake/Put 'Em In A Cage - Yellow Velvet Milk Club__v1.m4a` | Yellow Velvet Milk Club | Put 'Em In A Cage | `tbd (needs track id)` |
| `wavlake/GOD IS DECENTRALIZED - Zazawowow__v1.m4a` | Zazawowow | GOD IS DECENTRALIZED | `tbd (needs track id)` |

## Summary

- 81 tracks (7 artists) with a Nostr zap address
- 78 of them zap the artist's lightning address directly
- 35 tracks keep the Wavlake route (no Nostr profile found, or no track id yet)
