<?php
function isServerOnline($url) {
    $context = stream_context_create([
        'http' => ['timeout' => 2] // Set a 2-second timeout
    ]);
    $headers = @get_headers($url, 1, $context);
    return $headers && preg_match('/(200|301|302)/', $headers[0]);
}

$availableLogin = null;

$logins = ["http://system.betafoam.ph", "http://system2.betafoam.ph", "http://system3.betafoam.ph"];

foreach ($logins as $login) {
    if(isServerOnline($login)) {
        $availableLogin = $login;
        break;
    }
}
?>

<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        .btn-grad {
            background-image: linear-gradient(to right, #39dbff 0%, #1c9cce 51%, #00609f 100%)
        }

        .btn-grad {
            margin: 0px;
            padding: 15px 45px;
            text-align: center;
            text-transform: uppercase;
            transition: 0.5s;
            background-size: 200% auto;
            color: white;
            box-shadow: 0 0 5px #ddd;
            border-radius: 10px;
            display: block;
        }

        .btn-grad:hover {
            background-position: right center;
            /* change the direction of the change here */
            color: #fff;
            text-decoration: none;
        }

        p,
        h4, h3 {
            color: #0a0a0a;
        }

        body {
            background-color: #ffffff;
        }
    </style>
</head>

<body>
    <div class='container text-center w-100'> <br>
        <div class='text-center'> <img src="img/betalogo.png" alt="BETAFOAM"
                class="w-75"> <br> <br>
            <h2>Total Insulation and Specialized Packaging
            </h2> <br>
        </div> <br>
        <div class="text-center">
            <?php if($availableLogin) { ?>
            <a href="<?php echo $availableLogin.':1614'; ?>" class="col-sm-12 btn-grad w-75"> <strong>Betafoam System</strong> </a>
            <br> <br>
            <a href="<?php echo $availableLogin.':1613'; ?>" class="col-sm-12 btn-grad w-75"> <strong>CMS</strong> </a>
            <br> <br>
            <?php } else { ?>
            <h4>Connection error: Unable to connect to the systems. Please refresh the page and try again later.</h4>
            <br> <br>
            <button onclick="location.reload();" class="col-sm-12 btn-grad w-75"> <strong>Refresh Page</strong> </a> 
            <?php } ?>
        </div>
    </div>
</body>

</html>