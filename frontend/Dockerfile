# ===== BUILD STAGE =====
FROM node:20-alpine AS build
WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .

ARG VITE_API_URL
ENV VITE_API_URL=$VITE_API_URL
# Pass Vite APP URL argument (missing before)
ARG VITE_APP_URL
ENV VITE_APP_URL=$VITE_APP_URL

RUN npm run build

# ===== RUNTIME STAGE =====
FROM nginx:alpine

RUN rm -rf /usr/share/nginx/html/*

COPY --from=build /app/dist /usr/share/nginx/html
COPY ./nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
