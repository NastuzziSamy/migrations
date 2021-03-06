{{ '$table' }}@foreach ($command->getMigrationProperties() as $method => $args)->{{ $method }}({!!
	($args === [true] && $method !== 'default') ?
		'' :
		\implode(', ', \array_map(function ($arg) {
			return \is_array($arg) ? \implode(', ', \array_map(function ($subArg) {
				return \json_encode($subArg);
			}, $arg)) : \json_encode($arg);
		}, $args))
!!})@endforeach;
