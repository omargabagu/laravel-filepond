<?php

declare(strict_types=1);

namespace RahulHaque\Filepond\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RahulHaque\Filepond\Exceptions\InvalidChunkException;
use RahulHaque\Filepond\Models\Filepond;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\MimeTypes;
use phpseclib3\Net\SFTP;

use Throwable;

class FilepondService
{
    private $disk;

    private $tempDisk;

    private $tempFolder;

    private $model;

    public function __construct()
    {
        $this->disk = config('filepond.disk', 'public');
        $this->tempDisk = config('filepond.temp_disk', 'local');
        $this->tempFolder = config('filepond.temp_folder', '/temp');
        $this->model = config('filepond.model', Filepond::class);
    }

    /**
     * Validate the filepond file
     *
     * @return \Illuminate\Contracts\Validation\Validator
     */
    public function validator(Request $request, array $rules)
    {
        $field = array_key_first(Arr::dot($request->all()));

        return Validator::make($request->all(), [$field => $rules]);
    }

    /**
     * Store the uploaded file in the fileponds table
     *
     * @return string
     */
    public function store(Request $request)
    {
        $file = $this->getUploadedFile($request);

        $filepond = $this->model::create([
            'filepath' => $file->store($this->tempFolder, $this->tempDisk),
            'filename' => $file->getClientOriginalName(),
            'extension' => $file->getClientOriginalExtension(),
            'mimetypes' => $file->getClientMimeType(),
            'disk' => $this->disk,
            'created_by' => auth()->id(),
            'expires_at' => now()->addMinutes(config('filepond.expiration', 30)),
        ]);

        return Crypt::encrypt(['id' => $filepond->id]);
    }

    /**
     * Retrieve the filepond file from encrypted text
     *
     * @return mixed
     */
    public function retrieve(string $content)
    {
        $input = Crypt::decrypt($content);

        return $this->model::where('id', $input['id'])->firstOrFail();
    }

    /**
     * Initialize and make a slot for chunk upload
     *
     * @return string
     */
    public function initChunk()
    {
        $filepond = $this->model::create([
            'filepath' => '',
            'filename' => '',
            'extension' => '',
            'mimetypes' => '',
            'disk' => $this->disk,
            'created_by' => auth()->id(),
            'expires_at' => now()->addMinutes(config('filepond.expiration', 30)),
        ]);

        Storage::disk($this->tempDisk)->makeDirectory($this->tempFolder.'/'.$filepond->id);

        return Crypt::encrypt(['id' => $filepond->id]);
    }

    /**
     * Merge chunks
     *
     * @return string
     *
     * @throws Throwable
     */

    public function chunk(Request $request)
    {
        $id = Crypt::decrypt($request->patch)['id'];
        $filepond = $this->retrieve($request->patch);

        $uploadLength = (int) $request->header('Upload-Length');
        $uploadName = $request->header('Upload-Name');
        $uploadOffset = (int) $request->header('Upload-Offset');
        $chunkContent = $request->getContent();
        $chunkSize = strlen($chunkContent);

        Log::debug("Received chunk: {$uploadName} (offset: {$uploadOffset}, size: {$chunkSize})");

        // Validate chunk size
        $contentLength = (int) $request->header('Content-Length');
        if ($chunkSize === 0 || $chunkSize !== $contentLength) {
            Log::warning("Chunk size mismatch or empty chunk for: {$uploadName}");
            throw new InvalidChunkException;
        }

        if ($this->disk === 'sftp') {
            // SFTP streaming approach

            // Connect to SFTP via phpseclib
            $sftp = new SFTP(env('SFTP_HOST'), (int) env('SFTP_PORT', 22));
            if (!$sftp->login(env('SFTP_USERNAME'), env('SFTP_PASSWORD'))) {
                throw new \RuntimeException('SFTP Login Failed');
            }

            $remotePath = env('SFTP_ROOT', '') . "/temp/{$id}/{$uploadName}";

            // Ensure parent directory exists
            $dir = dirname($remotePath);
            if (!$sftp->file_exists($dir)) {
                $sftp->mkdir($dir, -1, true); // recursive mkdir
            }

            // Write chunk directly at offset
            if (!$sftp->put($remotePath, $chunkContent, SFTP::SOURCE_STRING, $uploadOffset)) {
                Log::error("Failed to write chunk to remote path: {$remotePath} at offset {$uploadOffset}");
                throw new \RuntimeException("Failed to write chunk to SFTP");
            }

            // Get current file size on remote
            $currentSize = $sftp->filesize($remotePath);

            if ($currentSize >= $uploadLength) {
                Log::debug("File upload complete on SFTP: {$remotePath}");
                $mimeType = (new MimeTypes())->getMimeTypes(pathinfo($uploadName, PATHINFO_EXTENSION))[0] ?? 'application/octet-stream';

                $filepond->update([
                    'filepath' => str_replace(env('SFTP_ROOT'), '', $remotePath), 
                    'filename' => $uploadName,
                    'extension' => pathinfo($uploadName, PATHINFO_EXTENSION),
                    'mimetypes' => $mimeType,
                    'disk' => 'sftp',
                    'created_by' => auth()->id(),
                    'expires_at' => now()->addMinutes(config('filepond.expiration', 30)),
                ]);
            }

            return $currentSize;

        } else {
            // Local disk chunk save and merge approach

            $dir = Storage::disk($this->tempDisk)->path($this->tempFolder.'/'.$id.'/');

            $chunkFilePath = $dir . $uploadOffset;

            $written = file_put_contents($chunkFilePath, $chunkContent);

            if ($written === false || $written === 0 || $written !== $chunkSize) {
                unlink($chunkFilePath);
                throw new InvalidChunkException;
            }

            // Sum all chunk sizes to check if full file uploaded
            $size = 0;
            $chunks = glob($dir . '*');
            foreach ($chunks as $chunk) {
                $size += filesize($chunk);
            }

            if ($size === $uploadLength) {
                // Merge chunks into final file
                $file = fopen($dir . $uploadName, 'w');
                foreach ($chunks as $chunk) {
                    $offset = (int) basename($chunk);
                    $chunkData = file_get_contents($chunk);
                    fseek($file, $offset);
                    fwrite($file, $chunkData);
                    unlink($chunk);
                }
                fclose($file);

                $mimeType = Storage::disk($this->tempDisk)->mimeType($this->tempFolder.'/'.$id.'/'.$uploadName);

                $filepond->update([
                    'filepath' => $this->tempFolder.'/'.$id.'/'.$uploadName,
                    'filename' => $uploadName,
                    'extension' => pathinfo($uploadName, PATHINFO_EXTENSION),
                    'mimetypes' => $mimeType,
                    'disk' => $this->disk,
                    'created_by' => auth()->id(),
                    'expires_at' => now()->addMinutes(config('filepond.expiration', 30)),
                ]);
        
            }

            return $size;
        }
    }


    /**
     * Get the offset of the last uploaded chunk for resume
     *
     * @return false|int
     */
    public function offset(string $content)
    {
        $filepond = $this->retrieve($content);

        $dir = Storage::disk($this->tempDisk)->path($this->tempFolder.'/'.$filepond->id.'/');
        $size = 0;
        $chunks = glob($dir.'*');
        foreach ($chunks as $chunk) {
            $size += filesize($chunk);
        }

        return $size;
    }

    /**
     * Retrieve the filepond file model and content
     *
     * @return mixed
     */
    public function restore(string $content)
    {
        $filepond = $this->retrieve($content);

        return [$filepond, Storage::disk($this->tempDisk)->get($filepond->filepath)];
    }

    /**
     * Delete the filepond file and record respecting soft delete
     *
     * @return bool|null
     */
    public function delete(Filepond $filepond)
    {
        if (config('filepond.soft_delete', true)) {
            return $filepond->delete();
        }

        Storage::disk($this->tempDisk)->delete($filepond->filepath);
        Storage::disk($this->tempDisk)->deleteDirectory($this->tempFolder.'/'.$filepond->id);

        return $filepond->forceDelete();
    }

    /**
     * Get the file from request
     *
     * @return mixed
     */
    protected function getUploadedFile(Request $request)
    {
        $field = array_key_first(Arr::dot($request->all()));

        return $request->file($field);
    }
}
