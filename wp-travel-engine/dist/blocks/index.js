(()=>{var e,t,o={7718:()=>{(()=>{const e=(e,t)=>{e.forEach((e=>e.classList.remove("active"))),t.classList.add("active")},t=e=>e.getBoundingClientRect().top+window.scrollY,o=document.querySelectorAll(".wpte-block_tab-button");o&&o.forEach(((t,r)=>{const a=t.closest(".wpte-block_tab-container").querySelectorAll(".wp-block-wptravelenginetripblocks-tab-content");0===r&&(t.classList.add("active"),a[0].removeAttribute("style")),t.addEventListener("click",(t=>{const r=t.target;a.forEach((e=>e.style.display="none"));const n=document.getElementById(r.dataset.id);n.style.display="block",e(o,r),window.innerWidth>767&&n.scrollIntoView({behavior:"smooth"})}))}));const r=document.querySelectorAll(".tab-direction-top.tab-is-sticky");window.onscroll=()=>{const o=window.scrollY;r&&window.innerWidth>767&&r.forEach((r=>{const a=r.querySelectorAll(".wp-block-wptravelenginetripblocks-tab-content"),n=Math.round(t(r)),l=r.querySelector(".wpte-block_tabs");o>=n?l.classList.add("fixed-top"):l.classList.remove("fixed-top"),a.forEach((r=>{const a=Math.round(t(r));if(o>=a){const t=document.querySelector(`[data-id="${r.getAttribute("id")}"]`),o=t.closest("ul").querySelectorAll(".wpte-block_tab-button");e(o,t)}}))}))};const a=document.querySelectorAll(".wpte-block_tab-content");a&&a.forEach((e=>{e.querySelectorAll(".wp-block-wptravelenginetripblocks-tab-content").forEach(((e,t)=>{e.style.order=t+1}))}))})()}},r={};function a(e){var t=r[e];if(void 0!==t)return t.exports;var n=r[e]={exports:{}};return o[e](n,n.exports,a),n.exports}a.m=o,a.n=e=>{var t=e&&e.__esModule?()=>e.default:()=>e;return a.d(t,{a:t}),t},a.d=(e,t)=>{for(var o in t)a.o(t,o)&&!a.o(e,o)&&Object.defineProperty(e,o,{enumerable:!0,get:t[o]})},a.f={},a.e=e=>Promise.all(Object.keys(a.f).reduce(((t,o)=>(a.f[o](e,t),t)),[])),a.u=e=>1991===e?"chunks/1991.js":1869===e?"chunks/1869.js":void 0,a.miniCssF=e=>{},a.g=function(){if("object"==typeof globalThis)return globalThis;try{return this||new Function("return this")()}catch(e){if("object"==typeof window)return window}}(),a.o=(e,t)=>Object.prototype.hasOwnProperty.call(e,t),e={},t="wp-travel-engine:",a.l=(o,r,n,l)=>{if(e[o])e[o].push(r);else{var c,d;if(void 0!==n)for(var s=document.getElementsByTagName("script"),i=0;i<s.length;i++){var u=s[i];if(u.getAttribute("src")==o||u.getAttribute("data-webpack")==t+n){c=u;break}}c||(d=!0,(c=document.createElement("script")).charset="utf-8",c.timeout=120,a.nc&&c.setAttribute("nonce",a.nc),c.setAttribute("data-webpack",t+n),c.src=o),e[o]=[r];var b=(t,r)=>{c.onerror=c.onload=null,clearTimeout(m);var a=e[o];if(delete e[o],c.parentNode&&c.parentNode.removeChild(c),a&&a.forEach((e=>e(r))),t)return t(r)},m=setTimeout(b.bind(null,void 0,{type:"timeout",target:c}),12e4);c.onerror=b.bind(null,c.onerror),c.onload=b.bind(null,c.onload),d&&document.head.appendChild(c)}},a.r=e=>{"undefined"!=typeof Symbol&&Symbol.toStringTag&&Object.defineProperty(e,Symbol.toStringTag,{value:"Module"}),Object.defineProperty(e,"__esModule",{value:!0})},(()=>{var e;a.g.importScripts&&(e=a.g.location+"");var t=a.g.document;if(!e&&t&&(t.currentScript&&"SCRIPT"===t.currentScript.tagName.toUpperCase()&&(e=t.currentScript.src),!e)){var o=t.getElementsByTagName("script");if(o.length)for(var r=o.length-1;r>-1&&(!e||!/^http(s?):/.test(e));)e=o[r--].src}if(!e)throw new Error("Automatic publicPath is not supported in this browser");e=e.replace(/#.*$/,"").replace(/\?.*$/,"").replace(/\/[^\/]+$/,"/"),a.p=e+"../"})(),(()=>{var e={3831:0};a.f.j=(t,o)=>{var r=a.o(e,t)?e[t]:void 0;if(0!==r)if(r)o.push(r[2]);else{var n=new Promise(((o,a)=>r=e[t]=[o,a]));o.push(r[2]=n);var l=a.p+a.u(t),c=new Error;a.l(l,(o=>{if(a.o(e,t)&&(0!==(r=e[t])&&(e[t]=void 0),r)){var n=o&&("load"===o.type?"missing":o.type),l=o&&o.target&&o.target.src;c.message="Loading chunk "+t+" failed.\n("+n+": "+l+")",c.name="ChunkLoadError",c.type=n,c.request=l,r[1](c)}}),"chunk-"+t,t)}};var t=(t,o)=>{var r,n,[l,c,d]=o,s=0;if(l.some((t=>0!==e[t]))){for(r in c)a.o(c,r)&&(a.m[r]=c[r]);d&&d(a)}for(t&&t(o);s<l.length;s++)n=l[s],a.o(e,n)&&e[n]&&e[n][0](),e[n]=0},o=globalThis.webpackChunkwp_travel_engine=globalThis.webpackChunkwp_travel_engine||[];o.forEach(t.bind(null,0)),o.push=t.bind(null,o.push.bind(o))})(),(()=>{"use strict";function e(e){return document.querySelectorAll(".wp-block-"+e).length>0}a(7718),new MutationObserver((function(){e("wptravelenginetripblocks-itinerary-altitude-chart")&&a.e(1991).then(a.bind(a,1991)).then((({initAltitudeChart:e})=>{e(chartData)})).catch((e=>{console.error("Error importing altitudeChart: ",e)})),e("wptravelenginetripblocks-faqs")&&a.e(1869).then(a.bind(a,1869)).then((({initFaqToggle:e})=>{e()})).catch((e=>{console.error("Error importing faqToggle: ",e)}))})).observe(document.body,{childList:!0,subtree:!0}),document.addEventListener("DOMContentLoaded",(function(){var e=document.querySelector(".show-less"),t=document.querySelector(".load-more");e&&(e.style.display="none",e.addEventListener("click",(function(){g("less")}))),t&&t.addEventListener("click",(function(){g("more")})),jQuery("body").on("change",".fsd-date-select",(function(){var e;e=document.querySelector(".select2-selection__rendered").textContent,fetch(wte_fix_date.ajaxurl,{method:"POST",body:new URLSearchParams({action:"handle_date_select",trip_id:h,selectedDate:e,dateFormat:b,customDateFormat:m,pagination_num:n,dateColumn:l,availabilityColumn:c,priceColumn:d,spaceColumn:s,startDate:i,endDate:u,loader_type:"date_select",bookButton:p.textContent,nonce:tripBlocksScript.nonce})}).then((e=>e.json())).then((e=>{const t=document.querySelector("#nestable1 tbody");t.querySelectorAll("tr").forEach((e=>{Array.from(e.cells).some((e=>e.textContent.includes("selectedDate")))||t.removeChild(e)})),document.querySelector(".load-more").dataset.current_page=1,document.querySelector(".show-less").dataset.current_page=1,1==document.querySelector(".show-less").dataset.current_page&&(document.querySelector(".show-less").style.display="none",document.querySelector(".load-more").style.display="block"),document.querySelector("#nestable1 tbody").insertAdjacentHTML("beforeend",e)})).catch((e=>{console.error("Error:",e)}))}))}));var t=document.querySelector(".dd-list.outer"),o=document.querySelector(".wpte-bf-btn.wte-book-now");if(t)var r=t.hasAttribute("data-fsd-count")?parseInt(t.getAttribute("data-fsd-count")):0,n=t.hasAttribute("data-pagination-number")?parseInt(t.getAttribute("data-pagination-number")):0,l=t.hasAttribute("data-date-column")?t.getAttribute("data-date-column"):"",c=t.hasAttribute("data-availability-column")?t.getAttribute("data-availability-column"):"",d=t.hasAttribute("data-price-column")?t.getAttribute("data-price-column"):"",s=t.hasAttribute("data-space-column")?t.getAttribute("data-space-column"):"",i=t.hasAttribute("data-start-date")?t.getAttribute("data-start-date"):"",u=t.hasAttribute("data-end-date")?t.getAttribute("data-end-date"):"",b=t.hasAttribute("data-date-format")?t.getAttribute("data-date-format"):"",m=t.hasAttribute("data-custom-date-format")?t.getAttribute("data-custom-date-format"):"";n>=r&&(document.querySelector(".load-more").style.display="none");var p=document.querySelector(".wte-fsd-list-booknow-btn"),y=document.querySelector(".load-more"),h=y?parseInt(y.getAttribute("data-trip-id")):NaN;h=isNaN(h)?1:h;var f=document.querySelectorAll(".book-btn");function g(e){var t=parseInt(document.querySelector(".load-more").dataset.current_page),o=document.querySelector(".select2-selection__rendered").textContent,a="more"===e?"more":"less",y=document.querySelector(".show-less"),f=document.querySelector(".load-more");y&&"less"===e&&(y.setAttribute("disabled","disabled"),y.classList.add("btn-loading")),f&&"more"===e&&(f.setAttribute("disabled","disabled"),f.classList.add("btn-loading")),fetch(wte_fix_date.ajaxurl,{method:"POST",body:new URLSearchParams({action:"handle_fsd_loader",current_page:t,pagination_num:n,trip_id:h,dateFormat:b,customDateFormat:m,loader_type:a,selectedDate:o,dateColumn:l,availabilityColumn:c,priceColumn:d,spaceColumn:s,startDate:i,endDate:u,bookButton:p.textContent,nonce:tripBlocksScript.nonce})}).then((e=>e.json())).then((o=>{if("more"===e)f&&(f.removeAttribute("disabled"),f.classList.remove("btn-loading")),document.querySelector("#nestable1 tbody").insertAdjacentHTML("beforeend",o[0]),t++,document.querySelector(".load-more").dataset.current_page=t,document.querySelector(".show-less").dataset.current_page=t,document.querySelector(".show-less").style.display="block",(d=document.querySelector("#nestable1 tbody").querySelectorAll("tr").length)===r&&(document.querySelector(".load-more").style.display="none"),d==o[1]&&(document.querySelector(".load-more").style.display="none");else if("less"===e){y&&(y.removeAttribute("disabled"),y.classList.remove("btn-loading"));var a=document.querySelectorAll(".wte-fsd-frontend-holders table tr"),l=Array.from(a),c=document.querySelector("#nestable1 tbody").querySelectorAll("tr").length;t--,document.querySelector(".load-more").dataset.current_page=t,document.querySelector(".show-less").dataset.current_page=t;var d,s=c-n;s>n?l.slice(-n).forEach((e=>{e.parentNode.removeChild(e)})):l.slice(-s).forEach((e=>{e.parentNode.removeChild(e)})),(d=document.querySelector("#nestable1 tbody").querySelectorAll("tr").length)===n&&(document.querySelector(".show-less").style.display="none"),(c===r||c<r)&&(document.querySelector(".load-more").style.display="block")}})).catch((e=>console.error("Error:",e)))}f&&f.forEach((function(e){e.setAttribute("disabled","disabled"),e.classList.add("btn-loading")})),document.addEventListener("bookingCalendarReady",(()=>{f.forEach((function(e){e.removeAttribute("disabled"),e.classList.remove("btn-loading")})),o||jQuery.fn.fancybox&&jQuery(".wte-fsd-list-booknow-btn").fancybox({type:"inline",src:"#wte__booking",baseClass:"wte-fb-popup",touch:!1})}))})()})();