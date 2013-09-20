<?php /* #?ini charset="utf-8"?

[ImportSettings]
AvailableSourceHandlers[]=alboimporthandler
AvailableSourceHandlers[]=archivioalboimporthandler

[alboimporthandler-HandlerSettings]
INI=alboimporthandler
Enabled=true
Name=Albo
ClassName=AlboImportHandler
DefaultParentNodeID=985
StreamTimeout=
SourceComuni=extension/ocimportalbo/data/lista_comuni.xml
FeedBase=http://www.albotelematico.tn.it/bacheca/---/exc.xml
FileUrl=http://www.albotelematico.tn.it/ftp/ANNO/ENTE/

[archivioalboimporthandler-HandlerSettings]
INI=alboimporthandler
Enabled=true
Name=Archivio Albo
ClassName=AlboImportHandler
DefaultParentNodeID=985
StreamTimeout=
SourceComuni=extension/ocimportalbo/data/lista_comuni.xml
FeedBase=http://www.albotelematico.tn.it/archivio/---/exc.xml
FileUrl=http://www.albotelematico.tn.it/ftp/ANNO/ENTE/

*/ ?>
