<?php

namespace FluentBoardsPro\App\Services;

class Constant
{
    const TRELLO_COLOR_MAP = [
        "green" => "#4bce97",
        "yellow" => "#f5cd47",
        "orange" => "#fea362",
        "red" => "#f87168",
        "purple" => "#9f8fef",
        "blue" => "#579dff",
        "sky" => "#6cc3e0",
        "lime" => "#baf3db",
        "pink" => "#e774bb",
        "black" => "#8590a2",
        "green_dark" => "#1f845a",
        "yellow_dark" => "#946f00",
        "orange_dark" => "#c25100",
        "red_dark" => "#ae2e24",
        "purple_dark" => "#5e4db2",
        "blue_dark" => "#0c66e4",
        "sky_dark" => "#206a83",
        "lime_dark" => "#5b7f24",
        "pink_dark" => "#943d73",
        "black_dark" => "#44546f",
        "green_light" => "#baf3db",
        "yellow_light" => "#f8e6a0",
        "orange_light" => "#fedec8",
        "red_light" => "#ffd5d2",
        "purple_light" => "#dfd8fd",
        "blue_light" => "#cce0ff",
        "sky_light" => "#c6edfb",
        "lime_light" => "#d3f1a7",
        "pink_light" => "#fdd0ec",
        "black_light" => "#dcdfe4"
    ];

    const TEXT_COLOR_MAP = [
        "green" => "#1B2533",
        "yellow" => "#1B2533",
        "orange" => "#1B2533",
        "red" => "#1B2533",
        "purple" => "#1B2533",
        "blue" => "#1B2533",
        "sky" => "#1B2533",
        "lime" => "#1B2533",
        "pink" => "#1B2533",
        "black" => "#1B2533",
        "green_dark" => "#fff",
        "yellow_dark" => "#fff",
        "orange_dark" => "#fff",
        "red_dark" => "#fff",
        "purple_dark" => "#fff",
        "blue_dark" => "#fff",
        "sky_dark" => "#fff",
        "lime_dark" => "#fff",
        "pink_dark" => "#fff",
        "black_dark" => "#fff",
        "green_light" => "#1B2533",
        "yellow_light" => "#1B2533",
        "orange_light" => "#1B2533",
        "red_light" => "#1B2533",
        "purple_light" => "#1B2533",
        "blue_light" => "#1B2533",
        "sky_light" => "#1B2533",
        "lime_light" => "#1B2533",
        "pink_light" => "#1B2533",
        "black_light" => "#1B2533"
    ];

    const TRELLO_URL = 'https://trello.com';
    const ASANA_URL = 'https://app.asana.com';

    const FLUENT_BOARDS_IMPORT = 'FluentBoards';

    const TASK_CUSTOM_FIELD = 'task_custom_field';

    const REPEAT_TASK_META = 'repeat_task';

    const AMAZON_S3_REGION = [
        'us-east-2',
        'us-east-1',
        'us-west-1',
        'us-west-2',
        'af-south-1',
        'ap-east-1',
        'ap-south-2',
        'ap-southeast-3',
        'ap-southeast-5',
        'ap-southeast-4',
        'ap-south-1',
        'ap-northeast-3',
        'ap-northeast-2',
        'ap-southeast-1',
        'ap-southeast-2',
        'ap-northeast-1',
        'ca-central-1',
        'ca-west-1',
        'eu-central-1',
        'eu-west-1',
        'eu-west-2',
        'eu-south-1',
        'eu-west-3',
        'eu-south-2',
        'eu-north-1',
        'eu-central-2',
        'il-central-1',
        'me-south-1',
        'me-central-1', 
        'sa-east-1',
        'us-gov-east-1',
        'us-gov-west-1',
        'cn-north-1',
        'cn-northwest-1'
    ];

    const DIGITAL_OCEAN_REGION = [
        'nyc1',
        'nyc2',
        'nyc3',
        'sfo2', 
        'sfo3',
        'ams3', 
        'sgp1', 
        'lon1', 
        'fra1', 
        'tor1',
        'blr1', 
        'syd1'
    ];

    const REGIONS = [
            "amazon_s3_region" => self::AMAZON_S3_REGION,
            "digital_ocean_region" => self::DIGITAL_OCEAN_REGION,
    ];

    const OBJECT_TYPE_FOLDER_BOARD = 'FluentBoardsPro\App\Models\Folder';
  
}