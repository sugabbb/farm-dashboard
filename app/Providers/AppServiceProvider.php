<?php

namespace App\Providers;

use Google\Cloud\Storage\StorageClient;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\{OpenApi,SecurityRequirement,SecurityScheme};
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility;
use League\Flysystem\Visibility;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('viewApiDocs',fn(?\App\Models\User $user)=>true);
        Scramble::afterOpenApiGenerated(function(OpenApi $openApi) {
            $openApi->components->addSecurityScheme('serviceKey',SecurityScheme::apiKey('header','X-PBB-API-Key')->as('serviceKey')->setDescription('Static key kept by the consuming backend proxy.'));
            $openApi->components->addSecurityScheme('abkBasic',SecurityScheme::http('basic')->as('abkBasic')->setDescription('Employee username and six-digit PIN.'));
            $openApi->security=[new SecurityRequirement(['serviceKey'=>[],'abkBasic'=>[]])];
        });
        Storage::extend('gcs',function($app,array $config) {
            $options=array_filter([
                'projectId'=>$config['project_id']??null,
                'keyFilePath'=>$config['key_file_path']??null,
            ]);
            $client=new StorageClient($options);
            $adapter=new GoogleCloudStorageAdapter(
                $client->bucket($config['bucket']),
                trim((string)($config['path_prefix']??''),'/'),
                new UniformBucketLevelAccessVisibility,
                Visibility::PUBLIC,
            );
            return new FilesystemAdapter(new Filesystem($adapter),$adapter,$config);
        });
        \Illuminate\Pagination\Paginator::useBootstrapFive();
        \Carbon\Carbon::setLocale('id');
    }
}
