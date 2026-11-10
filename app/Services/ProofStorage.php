<?php
namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Log,Storage};
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ProofStorage {
    public function replace(?UploadedFile $file,string $kind,?string $oldPath,callable $persist): mixed {
        if(!$file) return $persist(null);
        $proof=$this->upload($file,$kind);
        try { $result=$persist($proof); }
        catch(Throwable $exception) { $this->delete($proof['path']); throw $exception; }
        if($oldPath && $oldPath!==$proof['path']) $this->delete($oldPath);
        return $result;
    }

    private function upload(UploadedFile $file,string $kind): array {
        $mime=$file->getMimeType();
        $extension=$mime==='image/png'?'png':'jpg';
        $path=sprintf('proofs/%s/%s/%s.%s',$kind,now()->format('Y/m'),Str::uuid(),$extension);
        $url=$this->publicUrl($path);
        $stream=fopen($file->getRealPath(),'rb');
        if($stream===false) throw new RuntimeException('Bukti transaksi tidak dapat dibaca.');
        try {
            $stored=Storage::disk('gcs')->put($path,$stream,['visibility'=>'public','metadata'=>['contentType'=>$mime]]);
        } finally {
            // Some storage clients consume and close the stream during upload.
            if(is_resource($stream)) fclose($stream);
        }
        if(!$stored) throw new RuntimeException('Bukti transaksi gagal diunggah ke Google Cloud Storage.');
        return ['path'=>$path,'url'=>$url];
    }

    private function publicUrl(string $path): string {
        $bucket=trim((string)config('filesystems.disks.gcs.bucket'));
        if($bucket==='') throw new RuntimeException('GOOGLE_CLOUD_STORAGE_BUCKET belum dikonfigurasi.');
        $prefix=trim((string)config('filesystems.disks.gcs.path_prefix'),'/');
        $object=$prefix===''?$path:$prefix.'/'.$path;
        return 'https://storage.googleapis.com/'.$bucket.'/'.implode('/',array_map('rawurlencode',explode('/',$object)));
    }

    private function delete(string $path): void {
        try { Storage::disk('gcs')->delete($path); }
        catch(Throwable $exception) { Log::warning('Gagal menghapus bukti transaksi dari GCS.',['path'=>$path,'exception'=>$exception->getMessage()]); }
    }
}
