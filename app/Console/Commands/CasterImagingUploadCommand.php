<?php

namespace App\Console\Commands;

use App\Support\Caster\Blocks\JingleLibrary;
use App\Support\Caster\CasterFetcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * Uploads the Anchor's rendered jingles (caster:jingles, `blocks.jingles.dir`)
 * to the server as its imaging library (plan "TWENTY ONE Radio", P11), run on
 * the workstation: the manifest goes first; the server answers 409 with the
 * hashes it lacks, exactly those files follow (POST api/caster/imaging/files/{hash}),
 * then the manifest again. Unchanged files are never sent twice.
 */
#[Signature('caster:imaging-upload {--dir= : The rendered library (default: tv.commentary.blocks.jingles.dir)}')]
#[Description('Upload the rendered Anchor jingles to the server as its imaging library (workstation)')]
class CasterImagingUploadCommand extends Command
{
    public function handle(CasterFetcher $fetcher): int
    {
        $library = new JingleLibrary(is_string($this->option('dir')) && $this->option('dir') !== '' ? $this->option('dir') : JingleLibrary::fromConfig()->dir());
        $server = $fetcher->settings()['server'];
        $manifest = is_file($library->dir().'/manifest.json') ? json_decode((string) file_get_contents($library->dir().'/manifest.json'), true) : null;
        $texts = [];

        foreach ((array) (is_array($manifest) ? ($manifest['items'] ?? []) : []) as $item) {
            if (is_array($item) && is_string($item['id'] ?? null) && is_string($item['text'] ?? null)) {
                $texts[$item['id']] = $item['text'];
            }
        }

        $files = [];
        $items = [];

        foreach ($library->items() as $item) {
            if ($item['seconds'] <= 0) {
                continue;
            }

            $hash = sha1_file($item['file']) ?: '';
            $files[$hash] = $item['file'];
            $items[] = ['id' => $item['id'], 'line' => $item['line'], 'category' => $item['category'], 'seconds' => $item['seconds'], 'hash' => $hash, 'text' => $texts[$item['id']] ?? null];
        }

        if ($items === [] || $server === '') {
            $this->error($items === [] ? 'No rendered jingles in '.$library->dir().'.' : 'No server configured (tv.commentary.voicestudio.server).');

            return self::FAILURE;
        }

        try {
            $response = $fetcher->server()->post($server.'/api/caster/imaging', ['items' => $items]);
            $uploaded = 0;

            if ($response->status() === 409) {
                foreach ((array) $response->json('missing', []) as $hash) {
                    if (! is_string($hash) || ! isset($files[$hash])) {
                        continue;
                    }

                    $file = $fetcher->server()->withBody((string) file_get_contents($files[$hash]), 'audio/ogg')->post($server.'/api/caster/imaging/files/'.$hash);

                    if (! $file->successful()) {
                        $this->error('upload of '.basename($files[$hash]).' refused: '.$file->status().' '.$file->body());

                        return self::FAILURE;
                    }

                    $uploaded++;
                }

                $response = $fetcher->server()->post($server.'/api/caster/imaging', ['items' => $items]);
            }
        } catch (ConnectionException $exception) {
            $this->error('server unreachable: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('manifest refused: '.$response->status().' '.$response->body());

            return self::FAILURE;
        }

        $this->info(sprintf('imaging library on the server: %d items, %d files uploaded', count($items), $uploaded));

        return self::SUCCESS;
    }
}
