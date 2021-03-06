<?php

    require_once "../vendor/autoload.php";

    date_default_timezone_set('Asia/Taipei');

    spl_autoload_register(function ($classname) {
        require_once ("../helper/" . $classname . ".php");
    });

    use \Psr\Http\Message\ServerRequestInterface as Request;
    use \Psr\Http\Message\ResponseInterface as Response;

    use \peter\components\BotBuilder;

    use \GuzzleHttp\Client;

    use Dotenv\Dotenv;

    $dotenv = new Dotenv(__DIR__.'/..');
    $dotenv->load();

    $config = array(
        'db_type' => getenv('driver'),
        'db_host' => getenv('host'),
        'db_name' => getenv('database'),
        'db_username' => getenv('username'),
        'db_password' => getenv('password'),
    );

    $container = new \Slim\Container();

    $settings = $container->get('settings');

    // The Slim Framework settings

    $settings->replace([
        'displayErrorDetails' => true,
        'determineRouteBeforeAppMiddleware' => false,
        'debug' => true,
    ]);

    $container['notFoundHandler'] = function ($container) {
        //https://i.imgur.com/j7wPeJs.png
        return function ($request, $response) use ($container) {
            $contents = file_get_contents("../templates/404.html");
            return $container['response']
                ->withStatus(404)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->write($contents);
        };
    };

    // Register Twig View helper

    $container['view'] = function ($c) {
        $view = new \Slim\Views\Twig('../templates', [
            'cache' => false,
        ]);
        // Instantiate and add Slim specific extension

        $basePath = rtrim(str_ireplace('index.php', '', $c['request']->getUri()->getBasePath()), '/');
        $view->addExtension(new Slim\Views\TwigExtension($c['router'], $basePath));

        return $view;
    };

    // remember the write permision for the app.log

    $container['logger'] = function($c) {
        $logger = new \Monolog\Logger('my_logger');
        $file_handler = new \Monolog\Handler\StreamHandler("../logs/app.log");
        $logger->pushHandler($file_handler);
        return $logger;
    };

    $app = new \Slim\App($container);

    $tokens = json_decode(file_get_contents("../token/token.json"), true);
    $builder = new BotBuilder($tokens["token"], $tokens["page_token"]);

    $app->get('/', function(Request $request, Response $response) {
        $response->getBody()->write("<h1>歡迎來到追竹美食</h1><h2>目前版本為：v0.0.1</h2>");

        return $response;
    });

    $app->get('/term', function (Request $request, Response $response) {
        $response->getBody()->write("<h2>歡迎使用 food-bot(追竹美食) 請遵守Facebook Messenger 條款</h2>");

        return $response;
    });

    $app->get('/webhook', function(Request $request, Response $response) {
        global $builder;
        $result = $builder->verify("msg_token");
        $response->getBody()->write($result);
    });

    $app->post('/webhook', function(Request $request, Response $response) {
        global $builder;
        $data = $builder->receiveMsg();

        file_put_contents('../logs/msg.txt', json_encode($data));

        //get the graph sender id
        if(isset($data['entry'][0]['messaging'][0]['sender']['id'])) {
            $sender = $data['entry'][0]['messaging'][0]['sender']['id'];
        }

        //get the returned message
        if(isset($data['entry'][0]['messaging'][0]['message']['text'])) {
            $message = $data['entry'][0]['messaging'][0]['message']['text'];
        } else if(isset($data['entry'][0]['messaging'][0]['message']['attachments'])) {
            $message = $data['entry'][0]['messaging'][0]['message']['attachments'];
        } else if(isset($data['entry'][0]['messaging'][0]['postback'])) {
            $message = $data['entry'][0]['messaging'][0]['postback']['payload'];
        } else {
            $message = "not-find.";
            $response->getBody()->write($message);

            return $response;
        }

        //process the requested message(including nlp entity)*
        $process = new ProcessMessage($message, $sender);
        $message = mb_strtolower($message);
        $greetingMsg = ['你好', 'hello', '安安'];

        if(in_array($message, $greetingMsg)) {
            $json = $process->processText();
        } else if(isset($data['entry'][0]['messaging'][0]['message']['nlp']['entities']['local_search_query'])) {
            if($data['entry'][0]['messaging'][0]['message']['nlp']['entities']['local_search_query'][0]['confidence'] >= 0.9) {
                $term = $data['entry'][0]['messaging'][0]['message']['nlp']['entities']['local_search_query'][0]['value'];
                $json = $process->processGuessText('local_search_query', $term);
            } else {
                $json = $process->processText();
            }
        }/* else if(isset($data['entry'][0]['messaging'][0]['message']['nlp']['entities']['location'])) {
            if($data['entry'][0]['messaging'][0]['message']['nlp']['entities']['location'][0]['confidence'] >= 0.9) {
                $json = $process->processGuessText('location');
            } else {
                $json = $process->processText();
            }
        }*/ else {
            $json = $process->processText();
        }

        $body = array();
        $body["recipient"]["id"] = $sender;
        $body["sender_action"] = "typing_on";

        $builder->statusBubble($body);

        $res = $builder->sendMsg("texts", $data, $json);
    });

    $app->get('/add/menus', function(Request $request, Response $response) {
        global $builder;
        $menus = array(
            array(
                "type" => "postback",
                "title" => "請推薦美食",
                "payload" => "what_do_you_want_to_eat"
            ),
            array(
                "type" => "postback",
                "title" => "請推薦伴手禮",
                "payload" => "suggest_the_food_souvenir"
            ),
            array(
                "type" => "postback",
                "title" => "給我常用指令清單",
                "payload" => "give_me_command_lists"
            ),
        );

        $data = $builder->addMenu($menus);

        if($data === true) {
            $response->getBody()->write("add-menu-success");
            return $response;
        }
        else {
            $newResponse = $response->withAddedHeader('Content-type', 'application/json');
            $newResponse->getBody()->write(json_encode($data));
            return $newResponse;
        }
    });

    $app->get('/need_help', function(Request $request, Response $response) {
        $help = "使用方法";
        $json = file_get_contents("../json/usage.json");
        $json = json_decode($json, true);
        $usage = $json["usage"];
        $message = "";

        $index = 1;
        $len = count($usage);

        for($i=0;$i<$len;$i++) {
            if($index % 2 === 1) {
                $message .= "<tr>";
            }
            else {
                $message .= "<tr>";
            }

            $message .= "<td>" . $usage[$i]["cmd"] . "</td>";
            $message .= "<td>" . $usage[$i]["result"] . "</td>";

            $message .= "</tr>";

            $index += 1;
        }

        $this->logger->addInfo('Need Help');
        $response = $this->view->render($response, "usage.phtml", ["help" => $message]);
    });

    // route randomly Hsinchu Food
    $app->get('/eat_map', function(Request $request, Response $response) {

        global $config;

        $db = new Database($config);
        $stmt = $db->prepare("SELECT DISTINCT * FROM `food_storages` WHERE `address` LIKE '%新竹市%' AND `static_map_image` != '' ORDER BY RAND() LIMIT 1;");
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $address = $result['address'];
        $phoneNumber = $result['phone_number'];
        $rate = $result['rate'];
        $shopName = $result['shop_name'];
        $image = $result['static_map_image'];

        $message = '<tr>';
        $message .= '<td>'.$shopName.'</td>';
        $message .= '<td>'.$address.'</td>';
        $message .= '<td>'.$phoneNumber.'</td>';
        $message .= '<td>'.$rate.'</td>';
        $message .= '<td>'.'愛評網'.'</td>';
        $message .= '</tr>';
        $mapUrl = 'http://maps.google.com/?q='.urlencode($address);
        $image = "<a href='".$mapUrl."' target='_blank'><img class='center-block img-responsive' src='".$image."'></a>";

        $response = $this->view->render($response, "map.phtml", ["map_image" => $image, "eat_map_random" => $message]);
    });

    // route randomly Hsinchu Food
    $app->get('/eat_search_map/{term}', function(Request $request, Response $response, $args) {

        global $config;

        $term = urldecode($args['term']);

        $db = new Database($config);
        $stmt = $db->prepare("SELECT DISTINCT * FROM `food_storages` WHERE `shop_name` LIKE concat('%', :term, '%') AND `address`  LIKE '%新竹市%' AND `static_map_image` != '' ORDER BY RAND() LIMIT 1;", [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]);
        $stmt->execute([':term' => $term]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if(isset($result['address'])) {
            $address = $result['address'];
            $phoneNumber = $result['phone_number'];
            $rate = $result['rate'];
            $shopName = $result['shop_name'];
            $image = $result['static_map_image'];

            $message = '<tr>';
            $message .= '<td>'.$shopName.'</td>';
            $message .= '<td>'.$address.'</td>';
            $message .= '<td>'.$phoneNumber.'</td>';
            $message .= '<td>'.$rate.'</td>';
            $message .= '<td>'.'愛評網'.'</td>';
            $message .= '</tr>';
            $mapUrl = 'http://maps.google.com/?q='.urlencode($address);
            $image = "<a href='".$mapUrl."' target='_blank'><img class='center-block img-responsive' src='".$image."'></a>";

            $response = $this->view->render($response, "map.phtml", ["map_image" => $image, "eat_map_random" => $message]);
        } else {
            $response->getBody()->write("<h2>對不起，找不到你想要的：".$term."</h2>");

            return $response;
        }

    });

    // route randomly Hsinchu Food Souvenir
    $app->get('/souvenir_map', function(Request $request, Response $response, $args) {

        global $config;

        $db = new Database($config);
        $stmt = $db->prepare("SELECT DISTINCT * FROM `food_souvenirs` WHERE `address` != '' ORDER BY RAND() LIMIT 1;");
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $no = $result['no'];
        $productName = $result['product_name'];
        $shopName = $result['shop_name'];
        $address = $result['address'];
        $phoneNumber = $result['phone_number'];
        $shopWebsite = $result['shop_website'];
        $message = '<tr>';
        $message .= '<td>'.$no.'</td>';
        $message .= '<td>'.$productName.'</td>';
        $message .= '<td>'.$shopName.'</td>';
        $message .= '<td>'.$address.'</td>';
        $message .= '<td>'.$phoneNumber.'</td>';
        $message .= '<td>'.$shopWebsite.'</td>';
        $message .= '</tr>';
        $mapUrl = 'http://maps.google.com/?q='.urlencode($address);
        $staticMapImg = 'https://maps.googleapis.com/maps/api/staticmap?center={address}&markers=color:red|{address}&zoom=12&size=600x400&key={key}';
        $staticMapImg = str_replace('{address}', urlencode($address), $staticMapImg);
        $staticMapImg = str_replace('{key}', getenv('map_api_key'), $staticMapImg);
        $image = "<a href='".$mapUrl."' target='_blank'><img class='center-block img-responsive' src='".$staticMapImg."'></a>";
        $response = $this->view->render($response, "souvenir.phtml", ["map_image" => $image, "souvenir_map_random" => $message]);
    });

    $app->run();
