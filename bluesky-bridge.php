<?php
/**
 * Plugin Name: Bluesky Bridge
 * Description: Automatically posts blog titles to Bluesky (AT Protocol) when a post is published and checks for replies.
 * Version: 1.0.0
 * Author: David Miller
 */

// Trigger on publish
add_action('publish_post', 'bluesky_auto_post', 10, 2);

function bluesky_auto_post($ID, $post) {
    $username = get_option('bluesky_username');
    $app_password = get_option('bluesky_app_password');

    if (!$username || !$app_password) {
        return;
    }

    $title = get_the_title($ID);
    $url = get_permalink($ID);
//    $url = str_replace("http:","https:",$url);
    $tags = get_the_tags($ID);

    $excerpt = get_the_excerpt($ID);
//    $excerpt = htmlspecialchars_decode($excerpt);//Looking for a way to get characters like ' & - to display in Bluesky app (they work fine in OpenVibe)

    //Find any tags in $excerpt
    $beginAt = 0;
    while (strpos(substr($excerpt,$beginAt),"#")){
    	$beginAt = $beginAt + strpos(substr($excerpt,$beginAt),"#");
        if (strpos(substr($excerpt,$beginAt),";") == false 
        || strpos(substr($excerpt,$beginAt)," ") < strpos(substr($excerpt,$beginAt),";")){
        	if (strpos(substr($excerpt,$beginAt)," ") == false) {
            	$hashtag = substr($excerpt,$beginAt+1);
            } else {
		    	$hashtag = substr($excerpt,$beginAt+1,strpos(substr($excerpt,$beginAt)," "));
            }
            while(ctype_alnum($hashtag) == false) {//Ensure that we don't include special characters if the tag ends in something besides a space
            	$hashtag = substr($hashtag,0,strlen($hashtag)-1);
            }
            if(ctype_digit($hashtag) == false) {
	            $hashtags[] = "#" . $hashtag;
            }
        }
        $beginAt = $beginAt + strpos(substr($excerpt,$beginAt)," ");
    }

    $bluesky_text = $url . "\n" . $excerpt;
//    $bluesky_text = $excerpt . "\n" . $url;
//    $bluesky_text = $title . "\n" . $excerpt . "\n" . $url;
    if($tags && !is_wp_error($tags)){
        foreach($tags as $tag){
            $add_tag = " #" . preg_replace('/\s+/','_',$tag->name);// Replace spaces in tag with underscores
            if(strlen($add_tag) < 40){
                $bluesky_text = $bluesky_text . $add_tag;
            }
            $hashtags[] = '#' . preg_replace('/\s+/','_',$tag->name);
        }
    }

    if (strlen($bluesky_text) > 300) {
    	$trim_length = (strlen($bluesky_text) - 297);
        if (strlen($excerpt) > $trim_length){
            $new_excerpt = substr($excerpt,0,strrpos(substr($excerpt,0,-1*$trim_length)," "));
            $bluesky_text = $url . "\n" . $new_excerpt . "...";
        } else {
            $bluesky_text = $url . "\n";
        }
//        $bluesky_text = $new_excerpt . "...\n" . $url;
//        $bluesky_text = $title . "\n" . $new_excerpt . "...\n" . $url;
        if($tags && !is_wp_error($tags)){
            foreach($tags as $tag){
                $add_tag = " #" . preg_replace('/\s+/','_',$tag->name);// Replace spaces in tag with underscores
                if ((strlen($bluesky_text) + strlen($add_tag)) < 301) {
                    if(strlen($add_tag) < 40){
                        $bluesky_text = $bluesky_text . $add_tag;
                    }
                }
            }
        }
    }
//Looking for a way to get characters like ' & - to display in Bluesky app (they work fine in OpenVibe)
//    $bluesky_text = str_replace("'","''",$bluesky_text);
//    $bluesky_text = htmlspecialchars_encode($bluesky_text);

    $jwt = bluesky_get_jwt($username, $app_password);
    if ($jwt) {
        $response = bluesky_post_content($jwt, $username, $url, $bluesky_text, $hashtags);
        if (isset($response['uri'])) {//Wordpress record of Bluesky post information
            update_post_meta($ID, 'bluesky_uri', $response['uri']);
            update_post_meta($ID, 'bluesky_text', $bluesky_text);
        }
    }
}

function bluesky_get_jwt($username, $password) {
    $response = wp_remote_post('https://bsky.social/xrpc/com.atproto.server.createSession', [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'identifier' => $username,
            'password' => $password
        ])
    ]);

    if (is_wp_error($response)) return false;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['accessJwt'] ?? false;
}

function bluesky_post_content($jwt, $username, $url, $text, $hashtags) {
    $headers = [
        'Authorization' => 'Bearer ' . $jwt,
        'Content-Type'  => 'application/json',
    ];

    $facets = [];

    //Facet for URL
    $urlstart = mb_strpos($text,$url,0,'UTF-8');
    $urlend = $urlstart + mb_strlen($url,'UTF-8');
    $facets[] = [
        'index' => [
            'byteStart' => $urlstart,
            'byteEnd' => $urlend
        ],
        'features' => [
            [
                '$type' => 'app.bsky.richtext.facet#link',
                'uri' => $url
            ]
        ]
    ];
    //Facets for Hashtags
    foreach($hashtags as $hashtag){
        $start = mb_strpos($text,$hashtag,0,'UTF-8');
        if($start !== false){
            $facets[] = [
                'index' => [
                    'byteStart' => $start,
                    'byteEnd' => $start + mb_strlen($hashtag,'UTF-8'),
                ],
                'features' => [
                    [
                        '$type' => 'app.bsky.richtext.facet#tag',
                        'tag' => ltrim($hashtag,'#'),
                    ]
                ],
            ];
        }
    }

    $body = [
        'repo' => $username,
        'collection' => 'app.bsky.feed.post',
        'record' => [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'facets' => $facets,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ]
    ];

    $response = wp_remote_post('https://bsky.social/xrpc/com.atproto.repo.createRecord', [
        'headers' => $headers,
        'body' => json_encode($body)
    ]);

    if (is_wp_error($response)) return false;

    return json_decode(wp_remote_retrieve_body($response), true);
}

// Custom cron interval support
add_filter('cron_schedules', function($schedules) {
    $custom_interval = get_option('bluesky_cron_interval', 15);
    $interval_key = 'bluesky_' . $custom_interval . '_minutes';

    if (!isset($schedules[$interval_key])) {
        $schedules[$interval_key] = [
            'interval' => $custom_interval * 60,
            'display' => "Every $custom_interval minutes"
        ];
    }

    return $schedules;
});

// Reschedule cron job on interval change
function bluesky_reschedule_cron() {
    $interval = get_option('bluesky_cron_interval', 15);
    $hook = 'bluesky_check_replies_event';

    wp_clear_scheduled_hook($hook);
    wp_schedule_event(time(), 'bluesky_' . $interval . '_minutes', $hook);
}
add_action('update_option_bluesky_cron_interval', 'bluesky_reschedule_cron', 10, 2);

// Schedule default on activation
if (!wp_next_scheduled('bluesky_check_replies_event')) {
    wp_schedule_event(time(), 'bluesky_15_minutes', 'bluesky_check_replies_event');
}
add_action('bluesky_check_replies_event', 'bluesky_check_for_replies');

function bluesky_check_for_replies() {
    $username = get_option('bluesky_username');
    $app_password = get_option('bluesky_app_password');
    if (!$username || !$app_password) return;

    $jwt = bluesky_get_jwt($username, $app_password);
    if (!$jwt) return;

    $args = array(
        'post_type' => 'post',
        'meta_query' => array(
            array(
                'key' => 'bluesky_uri',
                'compare' => 'EXISTS'
            )
        )
    );

    $posts = get_posts($args);
    foreach ($posts as $post) {
        $uri = get_post_meta($post->ID, 'bluesky_uri', true);
        if (!$uri) continue;

        if (preg_match("/^at:(.+?)\/app.bsky.feed.post\/(.+)$/", $uri, $matches)) {
            $author = $matches[1];
            $rkey = $matches[2];

            $replies = bluesky_get_replies($jwt, $author, $rkey);
            foreach ($replies as $reply) {
                $content = $reply['record']['text'] ?? '';
                $reply_uri = $reply['uri'];

                $existing = get_comments([
                    'meta_key' => 'bluesky_reply_uri',
                    'meta_value' => $reply_uri,
                    'post_id' => $post->ID,
                    'count' => true
                ]);

                if ($existing == 0) {
                    wp_insert_comment([
                        'comment_post_ID' => $post->ID,
                        'comment_content' => $content,
                        'comment_author' => $reply['author']['handle'] ?? 'Bluesky User',
                        'comment_approved' => 1,
                        'comment_meta' => ['bluesky_reply_uri' => $reply_uri]
                    ]);
                }
            }
        }
    }
}

function bluesky_get_replies($jwt, $author, $rkey) {
    $headers = [
        'Authorization' => 'Bearer ' . $jwt,
    ];

    $params = http_build_query([
        'uri' => "at:$author/app.bsky.feed.post/$rkey"
    ]);

    $url = 'https://bsky.social/xrpc/app.bsky.feed.getPostThread?' . $params;
    $response = wp_remote_get($url, ['headers' => $headers]);

    if (is_wp_error($response)) return array();

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $replies = array();

    if (isset($data['thread']['replies'])) {
        foreach ($data['thread']['replies'] as $reply) {
            $replies[] = $reply['post'];
        }
    }

    return $replies;
}

// Admin settings
add_action('admin_menu', function () {
    add_options_page('Bluesky Bridge', 'Bluesky Bridge', 'manage_options', 'bluesky-bridge', 'bluesky_settings_page');
});

add_action('admin_init', function () {
    register_setting('bluesky-settings', 'bluesky_username');
    register_setting('bluesky-settings', 'bluesky_app_password');
    register_setting('bluesky-settings', 'bluesky_cron_interval');
});

function bluesky_settings_page() {
    ?>
    <div class="wrap">
        <h1>Bluesky Bridge Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('bluesky-settings'); ?>
            <?php do_settings_sections('bluesky-settings'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Bluesky Handle</th>
                    <td><input type="text" name="bluesky_username" value="<?php echo esc_attr(get_option('bluesky_username')); ?>" /></td>
                </tr>

                <tr valign="top">
                    <th scope="row">App Password</th>
                    <td><input type="password" name="bluesky_app_password" value="<?php echo esc_attr(get_option('bluesky_app_password')); ?>" /></td>
                </tr>

                <tr valign="top">
                    <th scope="row">Check Replies Every</th>
                    <td>
                        <input type="number" name="bluesky_cron_interval" value="<?php echo esc_attr(get_option('bluesky_cron_interval', 15)); ?>" min="1" /> minutes
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
