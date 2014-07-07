<?php

namespace Coderockr\SOA;

interface AuthorizationInterface
{
	public function isAuthorized($token, $resource);
}