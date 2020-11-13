<?php
    $confStr = file_get_contents('conf.json', false);
    $conf = json_decode($confStr);

    if (!$_GET['code'] && !$conf->code || array_key_exists('auth', $_GET)) { // We have this after authorization
        $rand = rand(1, 1000000);
        $loginUrl = 'https://www.reddit.com/api/v1/authorize' .
            '?client_id=' . $conf->client_id .
            '&response_type=code'.
            '&state=' . $rand .
            '&redirect_uri=' . $conf->redirect_uri .
            '&duration=' . $conf->duration .
            '&scope=' . $conf->scope;

        setcookie('podflair_rand', $rand, time() + (86400 * 30), "/");
        header('Location: ' . $loginUrl);

        die();
    }


    if ($_GET['state'] && $_COOKIE['podflair_rand'] !== $_GET['state']) {
        die('You didn\'t generate the proper local token');
    }

    if ($_GET['code']) {
        $conf->code = $_GET['code'];
    }

    // BASE OAUTH OBJECTS
    require('OAuth2/Client.php');
    require('OAuth2/GrantType/IGrantType.php');
    require('OAuth2/GrantType/AuthorizationCode.php');
    $userAgent = 'Podflair/0.1 by j0be';
    $client = new OAuth2\Client($conf->client_id, $conf->secret, OAuth2\Client::AUTH_TYPE_AUTHORIZATION_BASIC);
    $client->setCurlOption(CURLOPT_USERAGENT, $userAgent);
    $client->setAccessTokenType(OAuth2\Client::ACCESS_TOKEN_BEARER);

    function handleToken($result, $client, $conf) {
        $access_result = json_decode($result);

        $conf->access_token = $access_result->access_token;
        if ($access_result->refresh_token) {
            $conf->refresh_token = $access_result->refresh_token;
        }

        $fp = fopen('conf.json', 'w');
        fwrite($fp, json_encode($conf));
        fclose($fp);

        return $conf->access_token;
    }

    // If we have a new auth code, let's handle access tokens
    if ($_GET['code']) {
        // Now we need to get access token
        $url = 'https://www.reddit.com/api/v1/access_token';
        $data = array(
            'grant_type' => 'authorization_code',
            'code' => $conf->code,
            'redirect_uri' => $conf->redirect_uri
        );

        $options = array(
            'http' => array(
                'header'  =>
                    'Authorization: Basic ' . base64_encode($conf->client_id . ':' . $conf->secret) . "\r\n" .
                    'Content-type: application/x-www-form-urlencoded',
                'method'  => 'POST',
                'content' => http_build_query($data)
            )
        );
        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === FALSE) {
            die('We failed to get the access token from that code');
        }

        $token = handleToken($result, $client, $conf);
        $client->setAccessToken($token);

        $urlinfo = parse_url($_SERVER["REQUEST_URI"]);
        header('Location: ' . $urlinfo['path']);

        die();
    }

    $client->setAccessToken($conf->access_token);
    $postsUrl = 'https://oauth.reddit.com/r/centuryclub+ccpolitics/new.json?limit=100';
    $response = $client->fetch($postsUrl);

    if ($response['code'] === 401 && $conf->refresh_token) {
        echo "Expired token. Let's refresh\r\n";

        $url = 'https://www.reddit.com/api/v1/access_token';
        $data = array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $conf->refresh_token,
            'redirect_uri' => $conf->redirect_uri
        );

        $options = array(
            'http' => array(
                'header'  =>
                    'Authorization: Basic ' . base64_encode($conf->client_id . ':' . $conf->secret) . "\r\n" .
                    'Content-type: application/x-www-form-urlencoded',
                'method'  => 'POST',
                'content' => http_build_query($data)
            )
        );
        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === FALSE) {
            die('We failed to get the access token from that code');
        }

        $token = handleToken($result, $client, $conf);
        $client->setAccessToken($token);
    }

    function formatBias($str) {
        $str = strtolower($str);
        return "*" . preg_replace("/_/", " ", $str) . "*";
    }

    if ($response['code'] === 200) {
        $posts = $response['result']['data']['children'];
        if ($posts) {
            $domains = json_decode(file_get_contents('domains.json', false));
            $whitelist = $domains->whitelist;
            $blacklist = $domains->blacklist;
            $parsed = preg_split("/[\r\n]+/", file_get_contents('parsed.txt', false));

            foreach ($posts as $index => $post) {
                $postInfo = $post['data'];

                if (in_array($postInfo['domain'], $blacklist) || preg_match("/self\..*", $postInfo['domain'])) {
                    echo "Skipping domain " . $postInfo['domain'] . "\r\n";
                } else if (in_array($postInfo['name'], $parsed)) {
                    echo "Already parsed " . $postInfo['name'] . "\r\n";
                } else {
                    echo "Processing " . $postInfo['domain'] . "\r\n";

                    echo "Getting credibility for " .  $postInfo['url'] . "\r\n";
                    $credible = file_get_contents('https://forum.psci.me/news/credible?query=' .  $postInfo['url']);

                    if (!empty($credible)) {
                        $credible = json_decode($credible);
                        if ($credible->stats->total > 0) {
                            if (!in_array($postInfo['domain'], $whitelist)) {
                                array_push($whitelist, $postInfo['domain']);
                                $domains->whitelist = $whitelist;

                                $fp = fopen('domains.json', 'w');
                                fwrite($fp, json_encode($domains));
                                fclose($fp);
                            }

                            $str = '';

                            if (isset($credible->perspectives->most_relevant) && $credible->perspectives->most_relevant->relevancy_score > 99) {
                                $str .= "The linked article has [received a grade of *" . $credible->perspectives->most_relevant->score . "%*]";
                                $str .= "(https://www.isthiscredible.com/?url=" . $postInfo['url'] . ") ";
                                $str .= "(" . $credible->perspectives->most_relevant->pub_name . ", " . formatBias($credible->perspectives->most_relevant->bias) . ")    \r\n";
                                $str .= "\r\n\r\n";

                                $perspective_str = '';
                                if (isset($credible->perspectives->most_relevant) && isset($credible->perspectives->most_credible) && $credible->perspectives->most_relevant->story_id !== $credible->perspectives->most_credible->story_id) {
                                    $perspective_str .= "* Highest grade: ([*".$credible->perspectives->most_credible->score."%*](https://www.isthiscredible.com/?url=" . $credible->perspectives->most_credible->url . ")) ";
                                    $perspective_str .= "(" . $credible->perspectives->most_credible->pub_name . ", " . formatBias($credible->perspectives->most_credible->bias) . ")    \r\n";
                                    $perspective_str .= "[" . $credible->perspectives->most_credible->title . "]";
                                    $perspective_str .= "(" . $credible->perspectives->most_credible->url . ")\r\n\r\n";
                                } else if ($credible->perspectives->most_relevant->story_id === $credible->perspectives->most_credible->story_id) {
                                    $perspective_str .= "* This article received the highest grade of known articles";
                                }

                                if (isset($credible->perspectives->opp_credible)) {
                                    $perspective_str .= "* Highest grade from different political viewpoint: ([*".$credible->perspectives->opp_credible->score."%*](https://www.isthiscredible.com/?url=" . $credible->perspectives->opp_credible->url . ")) ";
                                    $perspective_str .= "(" . $credible->perspectives->opp_credible->pub_name . ", " . formatBias($credible->perspectives->opp_credible->bias) . ")    \r\n";
                                    $perspective_str .= "[" . $credible->perspectives->opp_credible->title . "]";
                                    $perspective_str .= "(" . $credible->perspectives->opp_credible->url . ")\r\n\r\n";
                                }

                                if (!empty($perspective_str)) {
                                    $str .= "**More perspective:**\r\n\r\n" . $perspective_str;
                                }
                            }


                            if ($str) {
                                $str .= "---\r\n\r\n^(**This is a bot running under j0be's account.**)";
                                echo "Posting comment to: " . $postInfo['name'] . "\r\n";

                                // Comment
                                $comment_url = 'https://oauth.reddit.com/api/comment';
                                $comment_response = $client->fetch($comment_url, array(
                                    'api_type' => 'json',
                                    'return_rtjson' => true,
                                    'text' => $str,
                                    'thing_id' => $postInfo['name']
                                ), 'POST');

                                if ($comment_response['result']) {
                                    // Make sure we don't parse this again
                                    $fp = fopen('parsed.txt', 'a');
                                    fwrite($fp, "\r\n" . $postInfo['name']);
                                    fclose($fp);

                                    // RSS
                                    $fp = fopen('comments.txt', 'a');
                                    fwrite($fp, "\r\n" . $postInfo['id']);
                                    fclose($fp);

                                    echo "Made comment for " . $postInfo['name'] . "\r\n";

                                    $distinguish_url = 'https://oauth.reddit.com/api/distinguish';
                                    $distinguish_response = $client->fetch($distinguish_url, array(
                                        'api_type' => 'json',
                                        'how' => 'yes',
                                        'id' => 't1_' . $comment_response['result']['id'],
                                        'sticky' => true
                                    ), 'POST');

                                    if ($distinguish_response) {
                                        echo "Distinguished " . $comment_response['result']['id'] . "<br>\r\n";
                                    } else {
                                        echo "Failed to distinguish " . $comment_response['result']['id'] . "<br>\r\n";
                                    }
                                } else {
                                    echo "Error submitting comment for " . $postInfo['name'] . ". Check again later.\r\n";
                                }
                            } else {
                                echo "Couldn\'t get any data. :/\r\n";
                            }
                        } else {
                            // Make sure we don't parse this again
                            $fp = fopen('parsed.txt', 'a');
                            fwrite($fp, "\r\n" . $postInfo['name']);
                            fclose($fp);

                            // if (!in_array($postInfo['domain'], $whitelist)) {
                            //     array_push($blacklist, $postInfo['domain']);
                            //     $domains->blacklist = $blacklist;

                            //     $fp = fopen('domains.json', 'w');
                            //     fwrite($fp, json_encode($domains));
                            //     fclose($fp);
                            // }

                            echo "This is not a news story, or has no results\r\n";
                        }
                    } else {
                        echo "Error getting credibility\r\n";
                    }
                }
            }
        } else {
            echo "Could not get any removals from the subreddit<br>\r\n";
            print_r($posts);
        }
    } else {
        echo "Reddit isn\'t working right now\r\n";
        print_r($response);
    }
?>