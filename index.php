<?php
session_start();

class SolarInverterSystem {

    public $inverterOverride = null;

    public $solarPower;
    public $batteryLevel;
    public $batteryCapacity;
    public $currentLoad = 0;
    public $devices = [];
    public $powerSource = "NONE";

    public function __construct($solar,$batteryPercent,$batteryCapacity){
        $this->solarPower = $solar;
        $this->batteryLevel = $batteryPercent;
        $this->batteryCapacity = $batteryCapacity;
        $this->updatePowerSource();
    }

    public function addDevice($name,$watt,$autoOnTime=null,$autoOffTime=null){
        $this->devices[$name]=[
            "watt"=>$watt,
            "status"=>false,
            "autoOnTime"=>$autoOnTime,
            "autoOffTime"=>$autoOffTime
        ];
    }

    private function updatePowerSource(){

    $hour=(int)date("H");

    if($this->inverterOverride===true){
        $this->powerSource="INVERTER";
        return;
    }

    if($this->inverterOverride===false){
        $this->powerSource="DC (MANUAL)";
        return;
    }

    if($hour>=6 && $hour<18){
        $this->powerSource="DC (Direct Current)";
    }else{
        $this->powerSource="INVERTER";
    }

}

    public function turnOn($name){

        if(!isset($this->devices[$name])) return;

        if($this->devices[$name]['status']) return;

        $this->devices[$name]['status']=true;
        $this->currentLoad+=$this->devices[$name]['watt'];

        $this->updatePowerSource();

        return $this->protectInverter();

    }

    public function turnOff($name){

        if(!isset($this->devices[$name])) return;

        if($this->devices[$name]['status']){

            $this->devices[$name]['status']=false;
            $this->currentLoad-=$this->devices[$name]['watt'];

            if($this->currentLoad<0){
                $this->currentLoad=0;
            }

        }

        $this->updatePowerSource();
    }

    public function protectInverter(){

    if(strpos($this->powerSource,"INVERTER") === false){
        return null;
    }

    if($this->currentLoad <= $this->batteryCapacity){
        return null;
    }

    $warning="⚠ INVERTER OVERLOAD! Devices were turned OFF automatically.";

    uasort($this->devices,function($a,$b){
        return $b['watt']-$a['watt'];
    });

    foreach($this->devices as $name=>$device){

        if($device['status']){

            $this->devices[$name]['status']=false;
            $this->currentLoad-=$device['watt'];

            if($this->currentLoad <= $this->batteryCapacity){
                break;
            }

        }

    }

    $this->updatePowerSource();

    return $warning;
}

    public function autoControl(){

        $hour=(int)date("H");

        foreach($this->devices as $name=>$device){

            if($device['autoOnTime']!==null && $hour==$device['autoOnTime']){
                $this->turnOn($name);
            }

            if($device['autoOffTime']!==null && $hour==$device['autoOffTime']){
                $this->turnOff($name);
            }

        }

        if($hour==6 && isset($this->devices['Lights'])){
            $this->turnOff("Lights");
        }

    }

    public function inverterOn(){
        $this->inverterOverride=true;
        $this->updatePowerSource();
    }

    public function inverterOff(){
        $this->inverterOverride=false;
        $this->updatePowerSource();
    }

    public function inverterAuto(){
        $this->inverterOverride=null;
        $this->updatePowerSource();
    }

}

if(!isset($_SESSION['system'])){
    $_SESSION['system']=new SolarInverterSystem(1000,80,2000);
}

$system=$_SESSION['system'];
$message="";

if(empty($system->devices)){

    $system->addDevice("Lights",200);
    $system->addDevice("Fan",150);
    $system->addDevice("TV",180);
    $system->addDevice("Fridge",700);
    $system->addDevice("AC",1500);

}

if($_SERVER["REQUEST_METHOD"]=="POST"){

    if(isset($_POST['addDevice'])){
        $system->addDevice($_POST['deviceName'],(int)$_POST['deviceWatt']);
    }

    if(isset($_POST['toggle'])){

        $name=$_POST['toggle'];

        if($system->devices[$name]['status']){
            $system->turnOff($name);
        }else{
            $msg=$system->turnOn($name);
            if($msg) $message=$msg;
        }

    }

    if(isset($_POST['inverter'])){

        $action=$_POST['inverter'];

        if($action=="on") $system->inverterOn();
        if($action=="off") $system->inverterOff();
        if($action=="auto") $system->inverterAuto();

    }

}

$system->autoControl();
$warn=$system->protectInverter();

if($warn) $message=$warn;

$_SESSION['system']=$system;

$loadPercent=($system->currentLoad/$system->batteryCapacity)*100;

if($loadPercent<=60){
$barColor="#22c55e";
}elseif($loadPercent<=90){
$barColor="#facc15";
}else{
$barColor="#ef4444";
}

if($loadPercent>100) $loadPercent=100;

?>

<!DOCTYPE html>
<html>
<head>

<title>Solar Inverter Dashboard</title>

<style>

body{
font-family:Arial;
background:#0f172a;
color:white;
padding:30px;
}

.card{
background:#1e293b;
padding:20px;
border-radius:10px;
margin-bottom:20px;
}

button{
padding:8px;
background:#38bdf8;
border:none;
border-radius:5px;
cursor:pointer;
}

button:hover{
background:#0ea5e9;
}

.on{color:#22c55e;}
.off{color:#94a3b8;}
.warning{color:#f87171;}

.load-bar-container{
width:100%;
background:#334155;
border-radius:10px;
overflow:hidden;
margin-top:10px;
}

.load-bar{
height:25px;
text-align:center;
line-height:25px;
color:white;
font-weight:bold;
}

</style>

</head>

<body>

<h1>⚡ Automated Solar Inverter</h1>

<?php if($message): ?>
<p class="warning"><?php echo $message; ?></p>
<?php endif; ?>

<div class="card">

<h2>Devices</h2>

<p>Time: <?php echo date("H:i"); ?></p>
<p>Power Source: <?php echo $system->powerSource; ?></p>
<p>Current Load: <?php echo $system->currentLoad; ?> W</p>

<div class="load-bar-container">
<div class="load-bar" style="width:<?php echo $loadPercent;?>%;background:<?php echo $barColor;?>;">
<?php echo round($loadPercent); ?>%
</div>
</div>

<br>

<?php foreach($system->devices as $name=>$device): ?>

<form method="POST" style="display:inline">

<button name="toggle" value="<?php echo $name;?>">
<?php echo $device['status']?"Turn OFF":"Turn ON";?>
</button>

<span class="<?php echo $device['status']?'on':'off';?>">
<?php echo "$name ({$device['watt']}W) - ".($device['status']?"ON":"OFF");?>
</span>

</form>

<br><br>

<?php endforeach; ?>

</div>

<div class="card">

<h2>Add Device</h2>

<form method="POST">

Device Name<br>
<input type="text" name="deviceName" required><br>

Watt Rating<br>
<input type="number" name="deviceWatt" required><br>

<button name="addDevice">Add Device</button>

</form>

</div>

<div class="card">

<h2>Inverter Manual Override</h2>

<form method="POST">

<button name="inverter" value="on">Force INVERTER ON</button>

<button name="inverter" value="off">Force INVERTER OFF</button>

<button name="inverter" value="auto">Back to AUTO</button>

</form>

<p>Current Mode: <?php echo $system->powerSource;?></p>

</div>

</body>
</html>