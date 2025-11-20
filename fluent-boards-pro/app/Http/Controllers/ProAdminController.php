<?php

namespace FluentBoardsPro\App\Http\Controllers;

use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\Framework\Support\Arr;
use FluentBoardsPro\App\Modules\CloudStorage\CloudStorageModule;
use FluentBoardsPro\App\Modules\CloudStorage\StorageHelper;

class ProAdminController extends Controller
{
    public function getStorageSettings(Request $request)
    {
        $config = StorageHelper::getConfig('view');

        return [
            'config' => $config
        ];
    }

    public function updateStorageSettings(Request $request)
    {
        if (defined('FLUENT_BOARDS_CLOUD_STORAGE') && FLUENT_BOARDS_CLOUD_STORAGE) {
            return $this->sendError([
                'message' => 'You can not update the storage settings as it is defined in the config file'
            ]);
        }

        $config = $request->get('config', []);

        $driver = Arr::get($config, 'driver', 'local');

        $validation = [
            'driver' => 'required'
        ];

        if ($driver == 'cloudflare_r2') {
            $validation = [
                'driver'     => 'required',
                'access_key' => 'required',
                'secret_key' => 'required',
                'bucket'     => 'required',
                'public_url' => 'required|url',
                'account_id' => 'required',
            ];
        } else if ($driver == 'amazon_s3') {
            $validation = [
                'driver'     => 'required',
                'region'     => 'required',
                'access_key' => 'required',
                'secret_key' => 'required',
                'bucket'     => 'required'
            ];
        } else if ($driver == 'digital_ocean') {
            $validation = [
                'driver'     => 'required',
                'region'     => 'required',
                'access_key' => 'required',
                'secret_key' => 'required',
                'bucket'     => 'required',
            ];
        } else if ($driver == 'blackblaze_b2') {
            $validation = [
                'driver'     => 'required',
                'endpoint'   => 'required',
                'bucket'     => 'required',
                'access_key'     => 'required', // also known as key id
                'secret_key'    => 'required' // also known as application key
            ];
        }

        $this->validate($config, $validation);


        if ($driver === 'cloudflare_r2' || $driver === 'amazon_s3' || $driver === 'blackblaze_b2' || $driver === 's3') {

            $previousConfig = StorageHelper::getConfig();

            if ($config['access_key'] == 'FBS_ENCRYPTED_DATA_KEY') {
                $config['access_key'] = Arr::get($previousConfig, 'access_key');
            }

            if ($config['secret_key'] == 'FBS_ENCRYPTED_DATA_KEY') {
                $config['secret_key'] = Arr::get($previousConfig, 'secret_key');
            }

            $driver = (new CloudStorageModule)->getConnectionDriver($config);

            if (!$driver) {
                return $this->sendError([
                    'message' => 'Could not connect to the remote storage service. Please check your credentials'
                ]);
            }

            $test = $driver->testConnection();

            if (!$test || is_wp_error($test)) {
                return $this->sendError([
                    'message' => 'Could not connect to the remote storage service. Error: ' . is_wp_error($test) ? $test->get_error_message() : 'Unknow Error'
                ]);
            }

        }

        $config = Arr::only($config, [
            'driver',
            'region',
            'access_key',
            'secret_key',
            'bucket',
            'public_url',
            'account_id',
            'sub_folder',
            'endpoint'
        ]);

        if ($config['driver'] == 'local') {
            $config = [
                'driver' => 'local'
            ];

            if(version_compare(FLUENT_BOARDS_VERSION, '1.90', '>=')) { 
                $featureConfig = fluent_boards_get_features_config();
                $featureConfig['cloud_storage'] = 'no';
                fluent_boards_update_option('_fbs_features', $featureConfig);
            } else {
                $featureConfig = fbsGetFeaturesConfig();
                $featureConfig['cloud_storage'] = 'no';
                fbsUpdateOption('_fbs_features', $featureConfig);
            }
        
            
        } else {
            if(version_compare(FLUENT_BOARDS_VERSION, '1.90', '>=')) { 
                $featureConfig = fluent_boards_get_features_config();
                $featureConfig['cloud_storage'] = 'yes';
                fluent_boards_update_option('_fbs_features', $featureConfig);
            } else {
                $featureConfig = fbsGetFeaturesConfig();
                $featureConfig['cloud_storage'] = 'yes';
                fbsUpdateOption('_fbs_features', $featureConfig);
            }
        }

        $config = array_filter($config);
        StorageHelper::updateConfig($config);

        return [
            'message' => __('Storage settings has been updated successfully', 'fluent-community')
        ];
    }

}