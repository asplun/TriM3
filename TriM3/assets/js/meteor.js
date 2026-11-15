/* TriM3 - 夜间星空流星 */
(function(){
  var c = document.getElementById('universe');
  if (!c) return;
  var ctx = c.getContext('2d');
  var stars = [], meteors = [], rafId = null, running = false;
  function resize(){c.width=window.innerWidth;c.height=window.innerHeight;stars=[];var n=Math.floor(c.width*c.height/8000);if(n<40)n=40;if(n>300)n=300;for(var i=0;i<n;i++){stars.push({x:Math.random()*c.width,y:Math.random()*c.height,r:Math.random()*1.2+0.3,o:Math.random(),s:Math.random()*0.02+0.005});}}
  function createMeteor(){var f=Math.random()<0.5;meteors.push({x:f?-40:Math.random()*c.width,y:Math.random()*c.height*0.4,len:Math.random()*80+50,sp:Math.random()*9+6,ang:f?-Math.PI/4:Math.PI/4,life:0});}
  function draw(){if(!running)return;ctx.clearRect(0,0,c.width,c.height);for(var i=0;i<stars.length;i++){var s=stars[i];s.o+=s.s;if(s.o>1||s.o<0.05)s.s=-s.s;ctx.beginPath();ctx.arc(s.x,s.y,s.r,0,Math.PI*2);ctx.fillStyle='rgba(255,255,255,'+Math.abs(s.o)+')';ctx.fill();}for(var j=meteors.length-1;j>=0;j--){var m=meteors[j];m.x+=Math.cos(m.ang)*m.sp;m.y+=Math.sin(m.ang)*m.sp;m.life++;var tx=m.x-Math.cos(m.ang)*m.len;var ty=m.y-Math.sin(m.ang)*m.len;var g=ctx.createLinearGradient(m.x,m.y,tx,ty);g.addColorStop(0,'rgba(255,255,255,1)');g.addColorStop(0.3,'rgba(173,216,230,0.6)');g.addColorStop(1,'rgba(255,255,255,0)');ctx.beginPath();ctx.moveTo(m.x,m.y);ctx.lineTo(tx,ty);ctx.strokeStyle=g;ctx.lineWidth=2;ctx.stroke();ctx.beginPath();ctx.arc(m.x,m.y,2,0,Math.PI*2);ctx.fillStyle='rgba(255,255,255,1)';ctx.fill();if(m.x<-80||m.x>c.width+80||m.y>c.height+80||m.life>120)meteors.splice(j,1);}if(Math.random()<0.015&&meteors.length<5)createMeteor();rafId=requestAnimationFrame(draw);}
  function start(){if(running)return;running=true;resize();draw();}
  function stop(){running=false;if(rafId)cancelAnimationFrame(rafId);rafId=null;ctx.clearRect(0,0,c.width,c.height);}
  function sync(){if(document.documentElement.classList.contains('dark'))start();else stop();}
  if(document.readyState!=='loading')sync();else document.addEventListener('DOMContentLoaded',sync);
  new MutationObserver(sync).observe(document.documentElement,{attributes:true,attributeFilter:['class']});
  window.addEventListener('resize',function(){if(running)resize();});
})();

// Swup navigation cleanup
document.addEventListener('swup:visit:start', function() {
  if (typeof stop === 'function') stop();
});
