<table width="100%" border="1" cellspacing="0" cellpadding="4">
    <tr>
        <th>Atto</th>
        <th>Errore</th>
        <th>Riga</th>
    </tr>
{foreach $errors as $error}
    <tr>
        <td><a href="http://www.albotelematico.tn.it/atto-pubb/{$error.row_id}">{$error.row_id}</a></td>
        <td>{$error.message|wash()}</td>
        <td><small>{$error.row|wash()}</small></td>
    </tr>
{/foreach}
</table>

<br />
<br />

<table width="100%" border="1" cellspacing="0" cellpadding="4">
<tr>
    <th>Sorgente</th>
    <td>{$feed}</td>
</tr>
<tr>
    <th>Argomenti</th>
    <td>{$arguments}</td>
</tr>
<tr>
    <th>Opzioni</th>
    <td>{$options}</td>
</tr>

</table>

<br />
<br />

{def $timestamp=currentdate()}
{$timestamp|l10n( 'shortdatetime' )}