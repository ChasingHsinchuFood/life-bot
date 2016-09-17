<?php
    use \GuzzleHttp\Client;

    class ProcessMessage {
        public function __construct($message, $sender) {
            $this->message =  strtolower($message);
            $this->sender = $sender;
        }

        public function processText() {
            $json = array();
            $json["recipient"]["id"] = $this->sender;

            $needle = "hello";
            $needleTw = "你好";

            if(mb_stristr($this->message, $needle) != false) {
                $json["message"]["text"] = "Hello! May I help you ? \n 嗨，有什麼事可以為你效勞的嘛？";
            }
            else if(mb_strlen($this->message) <= 5) {
                $json["message"]["text"] = "You have to input more texts so that I can understand what do you mean \n 你必須打更多的字好讓我看的懂！";
            }
            else {
                $messages = explode(",", $this->message);
                //e.g. 公車,台北,287
                //bus,Taipei,287
                //客運,9102
                //bus,9102

                if(count($messages) === 3) {
                    if(mb_stristr($messages[0], "公車") != false || mb_stristr($messages[0], "bus") != false) {
                        $busTime = new BusTime($messages);
                        $json["message"]["text"] = $busTime->getEstTime();
                    }
                    else {
                        $json["message"]["text"] = "公車動態服務無法達成！\nthe dynamic bus service is not successful";
                    }
                }
                else if(count($messages) === 2) {
                    if(mb_stristr($messages[0], "客運") != false || mb_stristr($messages[0], "bus") != false) {
                        $busTime = new BusTime($messages);
                        $json["message"]["text"] = $busTime->getEstTime();
                    }
                    else {
                        $json["message"]["text"] = "公車動態服務無法達成！\nthe dynamic bus service is not successful";
                    }
                }
                else if(mb_stristr($this->message, "I have to book my ticket") != false || mb_stristr($this->message, "我需要訂票") != false) {
                    $json["message"]["text"] = "Ok! please upload the 'ticket.txt'. \n 好的，請上傳『訂票檔』。";
                }
                else if(mb_stristr($this->message, "_dog_cat") != false) {
                    $json["message"]["attachment"]["type"] = "image";
                    $json["message"]["attachment"]["payload"]["url"] = $this->processPostBack();
                }
                else if(mb_stristr($this->message, "_") != false) {
                    $msg = $this->processPostBack();
                    $json["message"]["text"] = $msg;
                }
                else {
                    $json["message"]["text"] = "Sorry, this service is not available! \n 很抱歉，你說的這項服務我無法完成！";
                }
            }

            return $json;
        }

        public function processPostBack() {
            $message = "";

            if($this->message === "need_your_help") {
                $message = "使用說明在下列網址：\n the command lists is about the following url:\n";
                $message .= "https://peter-web.lionfree.net/life-bot/need_help";
            }
            else if($this->message === "city_lists") {
                $message = "城市清單在下列網址：\n the command lists is about the following url:\n";
                $message .= "https://peter-web.lionfree.net/life-bot/city_lists";
            }
            else if($this->message === "give_me_dog_cat") {
                srand();
                $randNum = rand(0,999);
                if($randNum % 2 !== 0)
                    $message = $this->getGif("dog");
                else
                    $message = $this->getGif("cat");
            }
            else if($this->message === "give_me_command_lists") {
                $usage = json_decode(file_get_contents("../json/usage.json"), true);
                
                $message = "useful commands,常用指令\n";
                $len = count($usage);

                for($i=0;$i<$len;$i++) {
                    $message .= "用法：" . $usage[$i]["ps"] . ";範例：" . $usage[$i]["cmd"] . "\n";
                }
            }
            else {
                $message = "invalid post back.";
            }

            return $message;
        }

        public function processImg() {
            $json = array();
            $json["recipient"]["id"] = $this->sender;
            $json["message"]["text"] = "Sorry, this service is not available! \n 很抱歉，你說的這項服務我無法完成！";
            return $json;
        }

        public function processFile() {
            $json = array();
            $json["recipient"]["id"] = $this->sender;
            $json["message"]["text"] = "Sorry, this service is not available! \n 很抱歉，你說的這項服務我無法完成！";
            return $json;
        }

        private function getGif($animal) {
            $url = "http://api.giphy.com/v1/gifs/search?q=funny+" . $animal . "&api_key=dc6zaTOxFJmzC";
            $client = new Client();
            $response = $client->request("GET", $url);
            $json = json_decode($response->getBody(), true);

            if($json["meta"]["status"] == 200) {
                $data = $json["data"];
                $dataLen = count($data) - 1;
                srand();
                $index = rand(0, $dataLen);
                return $data[$index]["images"]["original"]["url"];
            }
            else {
                return "https://valleytechnologies.net/wp-content/uploads/2015/07/error.png";
            }
        }

    }
?>
