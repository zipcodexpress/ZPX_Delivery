param([string]$ServerIp='192.168.86.225',[int]$Port=8443,[string]$Upstream='http://127.0.0.1:8000/')
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Net.Http
$listener=New-Object System.Net.HttpListener
$listener.Prefixes.Add("https://${ServerIp}:$Port/")
$client=New-Object System.Net.Http.HttpClient
$client.Timeout=[TimeSpan]::FromSeconds(30)
try {
 $listener.Start()
 while($listener.IsListening){
  $ctx=$listener.GetContext()
  $message=$null;$reply=$null
  try {
   $target=$Upstream.TrimEnd('/')+$ctx.Request.RawUrl
   $message=New-Object System.Net.Http.HttpRequestMessage ([System.Net.Http.HttpMethod]::new($ctx.Request.HttpMethod)), $target
   if($ctx.Request.HasEntityBody){$buffer=New-Object IO.MemoryStream;$ctx.Request.InputStream.CopyTo($buffer);$message.Content=New-Object System.Net.Http.ByteArrayContent (,$buffer.ToArray());$buffer.Dispose()}
   foreach($name in $ctx.Request.Headers.AllKeys){
    if($name -in @('Host','Content-Length','Connection','Transfer-Encoding','Expect')){continue}
    if(-not $message.Headers.TryAddWithoutValidation($name,$ctx.Request.Headers.GetValues($name)) -and $message.Content){$null=$message.Content.Headers.TryAddWithoutValidation($name,$ctx.Request.Headers.GetValues($name))}
   }
   $reply=$client.SendAsync($message).GetAwaiter().GetResult()
   $body=$reply.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()
   $ctx.Response.StatusCode=[int]$reply.StatusCode
   if($reply.Content.Headers.ContentType){$ctx.Response.ContentType=$reply.Content.Headers.ContentType.ToString()}
   foreach($header in $reply.Headers){if($header.Key -notin @('Connection','Transfer-Encoding','Keep-Alive')){foreach($value in $header.Value){$ctx.Response.AppendHeader($header.Key,$value)}}}
   $ctx.Response.ContentLength64=$body.Length;$ctx.Response.OutputStream.Write($body,0,$body.Length)
  }catch{$ctx.Response.StatusCode=502;$body=[Text.Encoding]::UTF8.GetBytes('{"error":{"code":"UPSTREAM_UNAVAILABLE","message":"The local API backend is unavailable."}}');$ctx.Response.ContentType='application/json';$ctx.Response.ContentLength64=$body.Length;$ctx.Response.OutputStream.Write($body,0,$body.Length)}
  finally{if($reply){$reply.Dispose()};if($message){$message.Dispose()};$ctx.Response.Close()}
 }
}finally{$listener.Close();$client.Dispose()}
