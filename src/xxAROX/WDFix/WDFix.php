<?php
/*
 *    Copyright 2022 Jan Sohn / xxAROX
 *
 *    Licensed under the Apache License, Version 2.0 (the "License");
 *    you may not use this file except in compliance with the License.
 *    You may obtain a copy of the License at
 *
 *        http://www.apache.org/licenses/LICENSE-2.0
 *
 *    Unless required by applicable law or agreed to in writing, software
 *    distributed under the License is distributed on an "AS IS" BASIS,
 *    WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *    See the License for the specific language governing permissions and
 *    limitations under the License.
 *
 */
declare(strict_types=1);
namespace xxAROX\WDFix;
use JsonMapper_Exception;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\handler\LoginPacketHandler;
use pocketmine\network\mcpe\JwtException;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\types\login\ClientData;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use ReflectionClass;
use ReflectionException;


/**
 * Class WDFix
 * @package xxAROX\WDFix
 * @author Jan Sohn / xxAROX
 * @date 17. Januar, 2022 - 22:52
 * @ide PhpStorm
 * @project WaterdogPE-LoginExtras-Fixer
 */
class WDFix extends PluginBase implements Listener{
	/**
	 * Function DataPacketReceiveEvent
	 * @param DataPacketReceiveEvent $event
	 * @return void
	 * @throws ReflectionException
	 * @priority MONITOR
	 * @handleCancelled true
	 */
	public function DataPacketReceiveEvent(DataPacketReceiveEvent $event): void{
		$packet = $event->getPacket();
		if ($packet instanceof LoginPacket) {
			try {
				[, $clientData,] = JwtUtils::parse($packet->clientDataJwt);
			} catch (JwtException $e) {
				throw PacketHandlingException::wrap($e);
			}
			$event->getOrigin()->setHandler(new class(Server::getInstance(), $event->getOrigin(), function (XboxLivePlayerInfo $info) use ($event, $clientData, $packet): void{
				$class = new ReflectionClass($event->getOrigin());
				$property = $class->getProperty("info");
				$property->setAccessible(true);
				$property->setValue($event->getOrigin(), new XboxLivePlayerInfo($clientData["Waterdog_XUID"], $info->getUsername(), $info->getUuid(), $info->getSkin(), $info->getLocale(), $info->getExtraData()));
			}, function (bool $isAuthenticated, bool $authRequired, ?string $error, ?string $clientPubKey) use ($event): void{
				$class = new ReflectionClass($event->getOrigin());
				$method = $class->getMethod("setAuthenticationStatus");
				$method->setAccessible(true);
				$method->invoke($event->getOrigin(), $isAuthenticated, $authRequired, $error, $clientPubKey);
			}) extends LoginPacketHandler{
				/**
				 * Function parseClientData
				 * @param string $clientDataJwt
				 * @return ClientData
				 */
				protected function parseClientData(string $clientDataJwt): ClientData{
					try {
						[, $clientDataClaims,] = JwtUtils::parse($clientDataJwt);
					} catch (JwtException $e) {
						throw PacketHandlingException::wrap($e);
					}
					$mapper = new \JsonMapper;
					$mapper->bEnforceMapType = false;
					$mapper->bExceptionOnMissingData = true;
					$mapper->bExceptionOnUndefinedProperty = true;
					try {
						$properties = array_map(fn(\ReflectionProperty $property) => $property->getName(), (new ReflectionClass(ClientData::class))->getProperties());
						foreach ($clientDataClaims as $k => $v) {
							if (!in_array($k, $properties)) {
								unset($clientDataClaims[$k]);
							}
						}
						unset($properties);
						$clientData = $mapper->map($clientDataClaims, new ClientData);
					} catch (JsonMapper_Exception $e) {
						throw PacketHandlingException::wrap($e);
					}
					return $clientData;
				}
			});
			if (isset($clientData["Waterdog_IP"])) {
				$class = new ReflectionClass($event->getOrigin());
				$property = $class->getProperty("ip");
				$property->setAccessible(true);
				$property->setValue($event->getOrigin(), $clientData["Waterdog_IP"]);
			}
			unset($clientData);
		}
	}

	/**
	 * Function onEnable
	 * @return void
	 */
	protected function onEnable(): void{
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}
}
