<?php

namespace Coderockr\SOA;

interface AuthenticationInterface
{
	public function authenticate($token);
}