<h1>Collocazioni Albo Telematico</h1>

<table class="list" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <th>Tipo atto in Albo telematico</th>
        <th>Classe OpenPa</th>
        <th>Collocazione</th>
    </tr>
    {foreach $location_hash as $tipo => $hash}
        {foreach $hash as $class => $location}
            <tr>
                <td>{$tipo}</td>
                <td>
                    {def $classObject = fetch( content, class, hash( 'class_id', $class ) )}
                    {if $classObject}
                        <a href={concat( 'class/view/', $classObject.id)|ezurl}>{$classObject.name|wash()}</a>
                    {else}
                        <strong>Non specificato</strong>
                    {/if}
                    {undef $classObject}
                </td>
                <td>
                    ({$location.type})
                    {foreach $location.node_ids as $node_id}
                        {def $locationNode = fetch( content, node, hash( 'node_id', $node_id ))}
                        {if $locationNode}
                            <a href={$locationNode.url_alias|ezurl}>{$locationNode.name|wash()}</a>
                        {else}
                            <strong>Non specificato</strong>
                        {/if}
                        {undef $locationNode}
                        {delimiter} / {/delimiter}
                    {/foreach}
                </td>
            </tr>
        {/foreach}
    {/foreach}
</table>

<h2>Test</h2>
<form method="post" action={'inigui/tools/albolocation'|ezurl()}>
    <textarea name="test"></textarea>
    <input class="button" type="submit" value="Test" />
</form>