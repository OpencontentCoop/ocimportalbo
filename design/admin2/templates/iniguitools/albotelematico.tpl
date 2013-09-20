<h1>Collocazioni Albo Telematico</h1>

<form method="post" action={'inigui/tools/albolocation'|ezurl()}>
    <textarea rows="5" class="box" name="test">{if $xml}{$xml}{/if}</textarea>
    <div class="block">
        <input class="button" type="submit" value="Test" />
    </div>
</form>

{if $test}
    <div class="warning message-warning block">
    {if is_array( $test )}
        {$test|attribute(show,4)}
    {else}
        <h4>{$test}</h4>
    {/if}
    </div>
{/if}

<table class="list" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <th>Tipo atto in Albo telematico</th>
        <th>Classe OpenPa</th>
        <th>Collocazione</th>
    </tr>
    {foreach $location_hash as $tipo => $hash sequence array( 'bgdark', 'bglight' ) as $sequence}
        {foreach $hash as $class => $location}
            <tr class="{$sequence}">
                <td style="vertical-align: middle;">{$tipo}</td>
                <td style="vertical-align: middle;">
                    {def $classObject = fetch( content, class, hash( 'class_id', $class ) )}
                    {if $classObject}
                        <a href={concat( 'class/view/', $classObject.id)|ezurl}>{$classObject.name|wash()}</a>
                    {else}
                        <strong>Non specificato</strong>
                    {/if}
                    {undef $classObject}
                </td>
                <td>
                    <small>configurato come {$location.type}</small><br />
                    <ul>
                    {foreach $location.node_ids as $node_id}
                        <li>
                        {def $locationNode = fetch( content, node, hash( 'node_id', $node_id ))}
                        {if $locationNode}
                            <a href={$locationNode.url_alias|ezurl}>{$locationNode.name|wash()} ({$node_id})</a>
                        {else}
                            <strong>Non specificato</strong>
                        {/if}
                        {undef $locationNode}
                        </li>
                    {/foreach}
                    </ul>
                </td>
            </tr>
        {/foreach}
    {/foreach}
</table>