local SubnauticaTweaks = {}
local php

function SubnauticaTweaks.filepath( name, width )
	return php.filepath( name, width )
end

function SubnauticaTweaks.setupInterface( options )
	-- Boilerplate
	SubnauticaTweaks.setupInterface = nil
	php = mw_interface
	mw_interface = nil

	-- Register this library in the "mw" global
	mw = mw or {}
	mw.ext = mw.ext or {}
	mw.ext.SubnauticaTweaks = SubnauticaTweaks

	package.loaded['mw.ext.SubnauticaTweaks'] = SubnauticaTweaks
end

return SubnauticaTweaks